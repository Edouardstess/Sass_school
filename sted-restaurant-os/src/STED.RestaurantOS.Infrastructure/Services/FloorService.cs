using Microsoft.Data.SqlClient;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Floor.Dtos;
using STED.RestaurantOS.Application.Security;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Domain.Floor.Events;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.Staff;
using STED.RestaurantOS.Infrastructure.Persistence;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Infrastructure.Services;

public sealed partial class FloorService : IFloorService
{
    // SQL Server's unique-constraint violations.
    private const int UniqueIndexViolation = 2601;
    private const int UniqueConstraintViolation = 2627;

    private readonly AppDbContext _db;
    private readonly IUnitOfWork _uow;
    private readonly ITenantContext _tenant;
    private readonly IDateTimeProvider _clock;
    private readonly IRealtimeNotifier _realtime;
    private readonly IQrTokenFactory _qr;
    private readonly NumberSequence _numbers;
    private readonly ILogger<FloorService> _logger;

    public FloorService(
        AppDbContext db,
        IUnitOfWork uow,
        ITenantContext tenant,
        IDateTimeProvider clock,
        IRealtimeNotifier realtime,
        IQrTokenFactory qr,
        NumberSequence numbers,
        ILogger<FloorService> logger)
    {
        _db = db;
        _uow = uow;
        _tenant = tenant;
        _clock = clock;
        _realtime = realtime;
        _qr = qr;
        _numbers = numbers;
        _logger = logger;
    }

    /// <inheritdoc />
    public async Task<Result<TableSessionDto>> TakeTableAsync(
        Guid tableId,
        TakeTableRequest request,
        CancellationToken ct)
    {
        if (_tenant.RestaurantId is not { } restaurantId)
        {
            return Error.Unauthorized("No restaurant in context.");
        }

        if (_tenant.StaffProfileId is not { } waiterId)
        {
            return Error.Forbidden("Only a staff member can take a table.");
        }

        try
        {
            return await _uow.ExecuteInTransactionAsync<Result<TableSessionDto>>(async token =>
            {
                var table = await _db.RestaurantTables
                    .FirstOrDefaultAsync(t => t.Id == tableId, token);

                if (table is null)
                {
                    return Error.Conflict(ErrorCodes.TableNotFound, "This table does not exist.");
                }

                if (!table.CanOpenSession)
                {
                    return Error.Conflict(
                        ErrorCodes.TableNotFound,
                        $"This table is {table.Status} and cannot be taken.");
                }

                var now = _clock.UtcNow;

                // Reuse the party that is already sitting there; only start a new
                // episode when the table is genuinely empty. Opening a second
                // session for the same guests would split their bill in two.
                var session = await _db.TableSessions
                    .Include(s => s.Assignments)
                    .FirstOrDefaultAsync(
                        s => s.TableId == tableId
                             && (s.Status == TableSessionStatus.Open || s.Status == TableSessionStatus.Active),
                        token);

                if (session is null)
                {
                    session = TableSession.Open(
                        restaurantId,
                        tableId,
                        await _numbers.NextSessionNumberAsync(restaurantId, token),
                        request.GuestCount,
                        waiterId,
                        now,
                        request.Notes);

                    _db.TableSessions.Add(session);
                }

                // Throws TABLE_ALREADY_ASSIGNED when someone else holds it.
                // Even if two requests both get past this check, the filtered
                // unique index below refuses the second insert.
                session.AssignWaiter(waiterId, waiterId, now, request.Notes);

                table.MarkOccupied();

                var events = await _uow.CommitTransactionAsync(token);
                await PublishAsync(events, token);

                var dto = await ProjectSessionAsync(session.Id, token);

                return dto is null
                    ? Error.NotFound("The session could not be read back.")
                    : dto;
            }, ct);
        }
        catch (DbUpdateException exception) when (IsUniqueViolation(exception))
        {
            // Two waiters tapped at the same moment and the database settled it.
            // Tell the loser who actually has the table rather than a bare error.
            var holder = await CurrentWaiterNameAsync(tableId, ct);

            _logger.LogInformation(
                "Concurrent take on table {TableId} lost by waiter {WaiterId}.",
                tableId,
                _tenant.StaffProfileId);

            return Error.Conflict(
                ErrorCodes.TableAlreadyAssigned,
                holder is null
                    ? "Another waiter has just taken this table."
                    : $"{holder} has just taken this table.");
        }
    }

    /// <inheritdoc />
    public async Task<Result<TableSessionDto>> TransferTableAsync(
        Guid tableId,
        TransferTableRequest request,
        CancellationToken ct)
    {
        if (_tenant.StaffProfileId is not { } actorId)
        {
            return Error.Forbidden("Only a staff member can transfer a table.");
        }

        var target = await _db.StaffProfiles
            .FirstOrDefaultAsync(s => s.Id == request.NewWaiterId, ct);

        if (target is null || !target.IsActive)
        {
            return Error.Validation("The receiving waiter is unknown or inactive.");
        }

        if (!target.CanTakeTables)
        {
            return Error.Validation($"{target.DisplayName} is not allowed to serve tables.");
        }

        var actingAsManager = IsManager();

        return await _uow.ExecuteInTransactionAsync<Result<TableSessionDto>>(async token =>
        {
            var session = await _db.TableSessions
                .Include(s => s.Assignments)
                .Include(s => s.History)
                .FirstOrDefaultAsync(
                    s => s.TableId == tableId
                         && (s.Status == TableSessionStatus.Open || s.Status == TableSessionStatus.Active),
                    token);

            if (session is null)
            {
                return Error.Conflict(ErrorCodes.TableNotAssigned, "This table has no open session.");
            }

            // The aggregate refuses the transfer unless the caller holds the
            // table or is a manager, and writes the history row itself.
            // Orders already placed keep their original waiter.
            session.TransferTo(request.NewWaiterId, actorId, actingAsManager, _clock.UtcNow, request.Reason);

            var events = await _uow.CommitTransactionAsync(token);
            await PublishAsync(events, token);

            var dto = await ProjectSessionAsync(session.Id, token);

            return dto is null
                ? Error.NotFound("The session could not be read back.")
                : dto;
        }, ct);
    }

    public async Task<Result<TableSessionDto>> ReleaseTableAsync(
        Guid tableId,
        ReleaseTableRequest request,
        CancellationToken ct)
    {
        if (_tenant.StaffProfileId is not { } actorId)
        {
            return Error.Forbidden("Only a staff member can release a table.");
        }

        return await _uow.ExecuteInTransactionAsync<Result<TableSessionDto>>(async token =>
        {
            var session = await _db.TableSessions
                .Include(s => s.Assignments)
                .Include(s => s.History)
                .FirstOrDefaultAsync(
                    s => s.TableId == tableId
                         && (s.Status == TableSessionStatus.Open || s.Status == TableSessionStatus.Active),
                    token);

            if (session is null)
            {
                return Error.Conflict(ErrorCodes.TableNotAssigned, "This table has no open session.");
            }

            session.UnassignWaiter(actorId, _clock.UtcNow, request.Reason);

            var events = await _uow.CommitTransactionAsync(token);
            await PublishAsync(events, token);

            var dto = await ProjectSessionAsync(session.Id, token);

            return dto is null
                ? Error.NotFound("The session could not be read back.")
                : dto;
        }, ct);
    }

    /// <inheritdoc />
    public async Task<Result<TableSessionDto>> CloseSessionAsync(Guid sessionId, CancellationToken ct)
    {
        if (_tenant.StaffProfileId is not { } actorId)
        {
            return Error.Forbidden("Only a staff member can close a session.");
        }

        return await _uow.ExecuteInTransactionAsync<Result<TableSessionDto>>(async token =>
        {
            var session = await _db.TableSessions
                .Include(s => s.Assignments)
                .Include(s => s.History)
                .FirstOrDefaultAsync(s => s.Id == sessionId, token);

            if (session is null)
            {
                return Error.NotFound("This session does not exist.");
            }

            // Orders live in another aggregate, so the fact is established here
            // and handed to the session rather than queried from inside it.
            var hasUnsettled = await _db.Orders.AnyAsync(
                o => o.TableSessionId == sessionId
                     && o.Status != OrderStatus.Closed
                     && o.Status != OrderStatus.Cancelled,
                token);

            session.Close(actorId, _clock.UtcNow, hasUnsettled);

            var table = await _db.RestaurantTables.FirstOrDefaultAsync(t => t.Id == session.TableId, token);
            table?.MarkCleaning();

            var events = await _uow.CommitTransactionAsync(token);
            await PublishAsync(events, token);

            var dto = await ProjectSessionAsync(session.Id, token);

            return dto is null
                ? Error.NotFound("The session could not be read back.")
                : dto;
        }, ct);
    }

    /// <inheritdoc />
    public async Task<Result<QrCodeIssuedDto>> RegenerateQrCodeAsync(Guid tableId, CancellationToken ct)
    {
        if (_tenant.StaffProfileId is not { } actorId)
        {
            return Error.Forbidden("Only a staff member can regenerate a QR code.");
        }

        var table = await _db.RestaurantTables
            .Include(t => t.QrCodes)
            .FirstOrDefaultAsync(t => t.Id == tableId, ct);

        if (table is null)
        {
            return Error.Conflict(ErrorCodes.TableNotFound, "This table does not exist.");
        }

        var material = _qr.Create();
        var now = _clock.UtcNow;

        // Issuing retires the previous code in the same call, so the "one active
        // code per table" rule is never observable as broken.
        var code = table.IssueQrCode(material.Hash, material.LookupKey, actorId, now);

        await _uow.SaveChangesAsync(ct);

        _logger.LogInformation(
            "QR code regenerated for table {TableNumber} by {StaffId}.", table.Number, actorId);

        // The clear token appears here and nowhere else, ever again.
        return new QrCodeIssuedDto(
            code.Id,
            table.Id,
            table.Number,
            $"/order/t/{material.ClearToken}",
            material.ClearToken,
            now,
            code.ExpiresAt);
    }

    private bool IsManager()
        => _tenant.IsPlatformAdministrator || CurrentRoles.Any(role =>
            role is RoleNames.Manager or RoleNames.RestaurantAdmin or RoleNames.SuperAdmin);

    /// <summary>
    /// Overridden in tests; in the API it comes from the authenticated principal.
    /// Kept as a property so the service has no dependency on HTTP.
    /// </summary>
    public IReadOnlyList<string> CurrentRoles { get; set; } = [];

    private static bool IsUniqueViolation(DbUpdateException exception)
        => exception.InnerException is SqlException sql
           && sql.Number is UniqueIndexViolation or UniqueConstraintViolation;

    private async Task<string?> CurrentWaiterNameAsync(Guid tableId, CancellationToken ct)
        => await _db.ServiceAssignments
            .Where(a => a.TableId == tableId && a.Status == ServiceAssignmentStatus.Active)
            .Join(_db.StaffProfiles, a => a.WaiterId, s => s.Id, (_, s) => s.DisplayName)
            .FirstOrDefaultAsync(ct);

    private async Task PublishAsync(IReadOnlyList<IDomainEvent> events, CancellationToken ct)
    {
        foreach (var domainEvent in events)
        {
            switch (domainEvent)
            {
                case WaiterAssignedDomainEvent assigned:
                    await _realtime.NotifyRestaurantAsync(
                        assigned.RestaurantId, RealtimeEvents.TableAssigned, assigned, ct);
                    await _realtime.NotifyUserAsync(
                        assigned.WaiterId, RealtimeEvents.TableAssigned, assigned, ct);
                    break;

                case TableTransferredDomainEvent transferred:
                    await _realtime.NotifyRestaurantAsync(
                        transferred.RestaurantId, RealtimeEvents.TableTransferred, transferred, ct);

                    // Both waiters need to know: one loses the table, one gains it.
                    await _realtime.NotifyUserAsync(
                        transferred.PreviousWaiterId, RealtimeEvents.TableTransferred, transferred, ct);
                    await _realtime.NotifyUserAsync(
                        transferred.NewWaiterId, RealtimeEvents.TableTransferred, transferred, ct);
                    break;

                case WaiterUnassignedDomainEvent unassigned:
                    await _realtime.NotifyRestaurantAsync(
                        unassigned.RestaurantId, RealtimeEvents.TableReleased, unassigned, ct);
                    break;

                case TableSessionClosedDomainEvent closed:
                    await _realtime.NotifyRestaurantAsync(
                        closed.RestaurantId, RealtimeEvents.SessionClosed, closed, ct);
                    await _realtime.NotifyTableAsync(
                        closed.TableId, RealtimeEvents.SessionClosed, closed, ct);
                    break;

                default:
                    break;
            }
        }
    }
}
