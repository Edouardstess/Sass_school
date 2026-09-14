using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Authentication.Dtos;
using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Security;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Infrastructure.Identity;
using STED.RestaurantOS.Infrastructure.Persistence;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Infrastructure.Services;

/// <summary>
/// Turns a scanned QR code into a usable, tightly scoped session.
/// <para>
/// This is the only anonymous write path in the system, so it is deliberately
/// narrow: it resolves a token, joins or opens a session, and hands back a token
/// that can do nothing but read that table's menu and place that table's orders.
/// </para>
/// </summary>
public sealed class GuestSessionService : IGuestSessionService
{
    private readonly AppDbContext _db;
    private readonly IUnitOfWork _uow;
    private readonly IQrTokenFactory _qr;
    private readonly IJwtTokenService _tokens;
    private readonly IDateTimeProvider _clock;
    private readonly IRealtimeNotifier _realtime;
    private readonly NumberSequence _numbers;
    private readonly ILogger<GuestSessionService> _logger;

    public GuestSessionService(
        AppDbContext db,
        IUnitOfWork uow,
        IQrTokenFactory qr,
        IJwtTokenService tokens,
        IDateTimeProvider clock,
        IRealtimeNotifier realtime,
        NumberSequence numbers,
        ILogger<GuestSessionService> logger)
    {
        _db = db;
        _uow = uow;
        _qr = qr;
        _tokens = tokens;
        _clock = clock;
        _realtime = realtime;
        _numbers = numbers;
        _logger = logger;
    }

    /// <inheritdoc />
    public async Task<Result<GuestSession>> ResolveAsync(string clearToken, CancellationToken ct)
    {
        // One message for every failure mode. Telling an unknown token apart
        // from a retired one would let anyone map the venue's tables.
        var invalid = Error.Conflict(ErrorCodes.QrCodeInvalid, "This QR code is not valid.");

        if (string.IsNullOrWhiteSpace(clearToken) || clearToken.Length is < 16 or > 128)
        {
            return invalid;
        }

        var lookupKey = _qr.LookupKeyOf(clearToken);
        var hash = _qr.Hash(clearToken);

        // The lookup key narrows the search to an index seek; the hash is what
        // actually authenticates, compared in constant time.
        var candidates = await _db.Set<TableQrCode>()
            .AsNoTracking()
            .Where(q => q.TokenLookupKey == lookupKey && q.IsActive)
            .ToListAsync(ct);

        var code = candidates.FirstOrDefault(c => QrTokenFactory.Matches(c.SecureTokenHash, hash));

        if (code is null || !code.IsUsable(_clock.UtcNow))
        {
            return invalid;
        }

        var context = await _db.RestaurantTables
            .IgnoreQueryFilters()
            .AsNoTracking()
            .Where(t => t.Id == code.TableId && t.IsActive)
            .Select(t => new
            {
                TableId = t.Id,
                t.Number,
                t.RestaurantId,
                Restaurant = _db.Restaurants
                    .IgnoreQueryFilters()
                    .Where(r => r.Id == t.RestaurantId)
                    .Select(r => new { r.Name, r.Currency, r.IsActive, r.Settings.AllowGuestOrdering, r.Settings.GuestTokenLifetimeHours })
                    .FirstOrDefault(),
            })
            .FirstOrDefaultAsync(ct);

        if (context?.Restaurant is null || !context.Restaurant.IsActive)
        {
            return invalid;
        }

        if (!context.Restaurant.AllowGuestOrdering)
        {
            return Error.Conflict(
                ErrorCodes.Forbidden,
                "This restaurant is not taking orders from the table right now.");
        }

        var sessionId = await ResolveSessionAsync(context.RestaurantId, context.TableId, ct);

        var lifetime = TimeSpan.FromHours(Math.Clamp(context.Restaurant.GuestTokenLifetimeHours, 1, 24));
        var token = _tokens.IssueGuestToken(context.RestaurantId, context.TableId, sessionId, lifetime);

        _logger.LogInformation(
            "Guest joined table {TableNumber} on session {SessionId}.", context.Number, sessionId);

        return new GuestSession
        {
            GuestToken = token.Token,
            ExpiresAt = token.ExpiresAt,
            RestaurantId = context.RestaurantId,
            RestaurantName = context.Restaurant.Name,
            TableId = context.TableId,
            TableNumber = context.Number,
            TableSessionId = sessionId,
            Currency = context.Restaurant.Currency,
        };
    }

    /// <summary>
    /// Joins the party already at the table, or starts a new one.
    /// <para>
    /// Six people at one table scanning six phones must land on one session, or
    /// they get six separate bills. The filtered unique index makes the race
    /// safe: whoever loses re-reads and joins the winner's session.
    /// </para>
    /// </summary>
    private async Task<Guid> ResolveSessionAsync(Guid restaurantId, Guid tableId, CancellationToken ct)
    {
        var existing = await _db.TableSessions
            .IgnoreQueryFilters()
            .Where(s => s.TableId == tableId
                        && (s.Status == TableSessionStatus.Open || s.Status == TableSessionStatus.Active))
            .Select(s => (Guid?)s.Id)
            .FirstOrDefaultAsync(ct);

        if (existing is { } sessionId)
        {
            return sessionId;
        }

        var now = _clock.UtcNow;

        var session = TableSession.Open(
            restaurantId,
            tableId,
            await _numbers.NextSessionNumberAsync(restaurantId, ct),

            // Nobody has told us how many they are. One is the honest default;
            // a waiter corrects it when they take the table.
            guestCount: 1,

            // Opened by the guest, so no staff member is credited with it.
            openedBy: Guid.Empty,
            now);

        _db.TableSessions.Add(session);

        try
        {
            await _uow.SaveChangesAsync(ct);
            return session.Id;
        }
        catch (DbUpdateException)
        {
            // Another phone at the same table won the race. Join their session.
            _db.Entry(session).State = EntityState.Detached;

            var winner = await _db.TableSessions
                .IgnoreQueryFilters()
                .Where(s => s.TableId == tableId
                            && (s.Status == TableSessionStatus.Open || s.Status == TableSessionStatus.Active))
                .Select(s => (Guid?)s.Id)
                .FirstOrDefaultAsync(ct);

            if (winner is null)
            {
                // Not a lost race after all: let the real failure surface.
                throw;
            }

            return winner.Value;
        }
    }

    public async Task<Result> CallWaiterAsync(Guid tableSessionId, CancellationToken ct)
    {
        var context = await SessionContextAsync(tableSessionId, ct);

        if (context is null)
        {
            return Error.NotFound("This session does not exist.");
        }

        var payload = new
        {
            context.TableId,
            context.TableNumber,
            TableSessionId = tableSessionId,
            At = _clock.UtcNow,
        };

        if (context.WaiterId is { } waiterId)
        {
            await _realtime.NotifyUserAsync(waiterId, "guest.called_waiter", payload, ct);
        }

        // Also to the room: if nobody has taken this table, someone still has to go.
        await _realtime.NotifyRestaurantAsync(context.RestaurantId, "guest.called_waiter", payload, ct);

        return Result.Success();
    }

    public async Task<Result> RequestBillAsync(Guid tableSessionId, CancellationToken ct)
    {
        var context = await SessionContextAsync(tableSessionId, ct);

        if (context is null)
        {
            return Error.NotFound("This session does not exist.");
        }

        var payload = new
        {
            context.TableId,
            context.TableNumber,
            TableSessionId = tableSessionId,
            At = _clock.UtcNow,
        };

        await _realtime.NotifyRoleAsync(
            context.RestaurantId, RoleNames.Cashier, RealtimeEvents.BillRequested, payload, ct);

        await _realtime.NotifyRestaurantAsync(
            context.RestaurantId, RealtimeEvents.BillRequested, payload, ct);

        return Result.Success();
    }

    private async Task<SessionContext?> SessionContextAsync(Guid sessionId, CancellationToken ct)
        => await _db.TableSessions
            .IgnoreQueryFilters()
            .AsNoTracking()
            .Where(s => s.Id == sessionId)
            .Select(s => new SessionContext(
                s.RestaurantId,
                s.TableId,
                _db.RestaurantTables.IgnoreQueryFilters()
                    .Where(t => t.Id == s.TableId)
                    .Select(t => t.Number)
                    .FirstOrDefault() ?? "?",
                s.Assignments
                    .Where(a => a.Status == ServiceAssignmentStatus.Active)
                    .Select(a => (Guid?)a.WaiterId)
                    .FirstOrDefault()))
            .FirstOrDefaultAsync(ct);

    private sealed record SessionContext(Guid RestaurantId, Guid TableId, string TableNumber, Guid? WaiterId);
}
