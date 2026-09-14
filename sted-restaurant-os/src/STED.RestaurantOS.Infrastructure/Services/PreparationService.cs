using Microsoft.EntityFrameworkCore;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Preparation.Dtos;
using STED.RestaurantOS.Domain.Catalog;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.Preparation;
using STED.RestaurantOS.Infrastructure.Persistence;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Infrastructure.Services;

/// <summary>
/// Drives the kitchen and bar screens.
/// <para>
/// When a station finishes its part, the parent order's status is recomputed
/// from all of its tickets: every ticket ready means READY, some means
/// PARTIALLY_READY. That is what lets a waiter carry the drinks out while the
/// main course is still cooking, instead of everything waiting for the slowest
/// station.
/// </para>
/// </summary>
public sealed class PreparationService : IPreparationService
{
    private readonly AppDbContext _db;
    private readonly IUnitOfWork _uow;
    private readonly ITenantContext _tenant;
    private readonly IDateTimeProvider _clock;
    private readonly IRealtimeNotifier _realtime;

    public PreparationService(
        AppDbContext db,
        IUnitOfWork uow,
        ITenantContext tenant,
        IDateTimeProvider clock,
        IRealtimeNotifier realtime)
    {
        _db = db;
        _uow = uow;
        _tenant = tenant;
        _clock = clock;
        _realtime = realtime;
    }

    public async Task<Result<StationSnapshotDto>> GetSnapshotByCodeAsync(
        string stationCode,
        CancellationToken ct)
    {
        var stationId = await _db.Stations
            .Where(s => s.Code == stationCode.ToUpperInvariant())
            .Select(s => (Guid?)s.Id)
            .FirstOrDefaultAsync(ct);

        return stationId is null
            ? Error.NotFound($"No station '{stationCode}' in this restaurant.")
            : await GetSnapshotAsync(stationId.Value, ct);
    }

    /// <inheritdoc />
    public async Task<Result<StationSnapshotDto>> GetSnapshotAsync(Guid stationId, CancellationToken ct)
    {
        var station = await _db.Stations
            .AsNoTracking()
            .Where(s => s.Id == stationId)
            .Select(s => new { s.Id, s.Code })
            .FirstOrDefaultAsync(ct);

        if (station is null)
        {
            return Error.NotFound("This station does not exist.");
        }

        var thresholds = await ThresholdsAsync(station.Code, ct);
        var now = _clock.UtcNow;

        // Only open tickets, plus whatever became ready recently so the pass
        // still shows it. A screen that accumulates a whole service is unusable.
        var readyCutoff = now.AddMinutes(-30);

        var tickets = await _db.PreparationTickets
            .AsNoTracking()
            .Include(t => t.Items)
            .Where(t => t.StationId == stationId
                        && (t.Status == PreparationTicketStatus.New
                            || t.Status == PreparationTicketStatus.Accepted
                            || t.Status == PreparationTicketStatus.InPreparation
                            || (t.Status == PreparationTicketStatus.Ready && t.ReadyAt >= readyCutoff)))
            .OrderBy(t => t.CreatedAtUtc)
            .ToListAsync(ct);

        var orderIds = tickets.Select(t => t.OrderId).Distinct().ToList();

        var orderInfo = await _db.Orders
            .AsNoTracking()
            .Where(o => orderIds.Contains(o.Id))
            .Select(o => new
            {
                o.Id,
                OrderNumber = o.OrderNumber.Value,
                o.Notes,
                TableNumber = _db.RestaurantTables
                    .Where(t => t.Id == o.TableId)
                    .Select(t => t.Number)
                    .FirstOrDefault(),
                WaiterName = _db.StaffProfiles
                    .Where(s => s.Id == o.WaiterId)
                    .Select(s => s.DisplayName)
                    .FirstOrDefault(),
            })
            .ToDictionaryAsync(o => o.Id, ct);

        var dtos = tickets.Select(t =>
        {
            var info = orderInfo.GetValueOrDefault(t.OrderId);
            var elapsed = t.ElapsedAt(now);

            return new PreparationTicketDto
            {
                Id = t.Id,
                TicketNumber = t.TicketNumber,
                OrderId = t.OrderId,
                OrderNumber = info?.OrderNumber ?? "?",
                StationId = t.StationId,
                StationCode = station.Code,
                TableNumber = info?.TableNumber ?? "?",
                WaiterName = info?.WaiterName,
                Status = t.Status,
                CreatedAt = t.CreatedAtUtc,
                AcceptedAt = t.AcceptedAt,
                ReadyAt = t.ReadyAt,
                ElapsedSeconds = (int)elapsed.TotalSeconds,
                IsLate = t.IsLateAt(now, thresholds.Late),
                IsWarning = t.IsLateAt(now, thresholds.Warning),
                OrderNotes = info?.Notes,
                Items = t.Items.Select(i => new PreparationTicketItemDto(
                    i.Id, i.ProductNameSnapshot, i.Quantity, i.Notes, i.ModifiersSummary, i.Status)).ToList(),
            };
        }).ToList();

        return new StationSnapshotDto
        {
            StationId = station.Id,
            StationCode = station.Code,

            // The client syncs its countdown to this rather than to the device
            // clock, which on a cheap tablet can be minutes out.
            ServerTime = now,

            New = dtos.Where(t => t.Status == PreparationTicketStatus.New).ToList(),
            InPreparation = dtos.Where(t => t.Status is PreparationTicketStatus.Accepted
                                            or PreparationTicketStatus.InPreparation).ToList(),
            Ready = dtos.Where(t => t.Status == PreparationTicketStatus.Ready).ToList(),
            LateCount = dtos.Count(t => t.IsLate),
        };
    }

    public Task<Result<PreparationTicketDto>> AcceptAsync(Guid ticketId, CancellationToken ct)
        => MutateAsync(ticketId, (ticket, staffId, now) => ticket.Accept(staffId, now), ct);

    public Task<Result<PreparationTicketDto>> StartAsync(Guid ticketId, CancellationToken ct)
        => MutateAsync(ticketId, (ticket, staffId, now) => ticket.Start(staffId, now), ct);

    public Task<Result<PreparationTicketDto>> MarkReadyAsync(Guid ticketId, CancellationToken ct)
        => MutateAsync(ticketId, (ticket, staffId, now) => ticket.MarkReady(staffId, now), ct);

    public Task<Result<PreparationTicketDto>> MarkPickedUpAsync(Guid ticketId, CancellationToken ct)
        => MutateAsync(ticketId, (ticket, staffId, now) => ticket.MarkPickedUp(staffId, now), ct);

    private async Task<Result<PreparationTicketDto>> MutateAsync(
        Guid ticketId,
        Action<PreparationTicket, Guid, DateTimeOffset> change,
        CancellationToken ct)
    {
        if (_tenant.StaffProfileId is not { } staffId)
        {
            return Error.Forbidden("Only a staff member can update a ticket.");
        }

        return await _uow.ExecuteInTransactionAsync<Result<PreparationTicketDto>>(async token =>
        {
            var ticket = await _db.PreparationTickets
                .Include(t => t.Items)
                .FirstOrDefaultAsync(t => t.Id == ticketId, token);

            if (ticket is null)
            {
                return Error.NotFound("This ticket does not exist.");
            }

            change(ticket, staffId, _clock.UtcNow);

            await SyncOrderStatusAsync(ticket.OrderId, staffId, token);

            var events = await _uow.CommitTransactionAsync(token);
            await PublishAsync(events, token);

            var snapshot = await GetSnapshotAsync(ticket.StationId, token);

            if (snapshot.IsFailure)
            {
                return Error.NotFound("The ticket could not be read back.");
            }

            var dto = snapshot.Value!.New
                .Concat(snapshot.Value.InPreparation)
                .Concat(snapshot.Value.Ready)
                .FirstOrDefault(t => t.Id == ticketId);

            return dto is null
                ? Error.NotFound("The ticket is no longer on this screen.")
                : dto;
        }, ct);
    }

    /// <summary>
    /// Recomputes the order's status from its tickets. This is the eventual
    /// consistency the separate-aggregate decision buys, and it closes here —
    /// inside the same transaction as the ticket change.
    /// </summary>
    private async Task SyncOrderStatusAsync(Guid orderId, Guid staffId, CancellationToken ct)
    {
        var order = await _db.Orders
            .Include(o => o.Items)
            .FirstOrDefaultAsync(o => o.Id == orderId, ct);

        if (order is null || order.IsSettled)
        {
            return;
        }

        var statuses = await _db.PreparationTickets
            .Where(t => t.OrderId == orderId && t.Status != PreparationTicketStatus.Cancelled)
            .Select(t => t.Status)
            .ToListAsync(ct);

        if (statuses.Count == 0)
        {
            return;
        }

        var now = _clock.UtcNow;

        var allDone = statuses.All(s => s is PreparationTicketStatus.Ready or PreparationTicketStatus.PickedUp);
        var anyDone = statuses.Any(s => s is PreparationTicketStatus.Ready or PreparationTicketStatus.PickedUp);
        var anyStarted = statuses.Any(s => s is PreparationTicketStatus.Accepted
                                           or PreparationTicketStatus.InPreparation);

        if (allDone && order.Status != OrderStatus.Ready)
        {
            order.MarkReady(staffId, now);
        }
        else if (anyDone && order.Status is OrderStatus.Confirmed or OrderStatus.InPreparation)
        {
            order.MarkPartiallyReady(staffId, now);
        }
        else if (anyStarted && order.Status == OrderStatus.Confirmed)
        {
            order.MarkInPreparation(staffId, now);
        }
    }

    private async Task<(int Warning, int Late)> ThresholdsAsync(string stationCode, CancellationToken ct)
    {
        var settings = await _db.Restaurants
            .AsNoTracking()
            .Include(r => r.Settings)
            .Select(r => new
            {
                r.Settings.KdsWarningThresholdMinutes,
                r.Settings.KdsLateThresholdMinutes,
                r.Settings.BarWarningThresholdMinutes,
                r.Settings.BarLateThresholdMinutes,
            })
            .FirstOrDefaultAsync(ct);

        if (settings is null)
        {
            return (8, 15);
        }

        // A drink that has waited five minutes is late; a slow-cooked dish is not.
        return stationCode == StationCodes.Bar
            ? (settings.BarWarningThresholdMinutes, settings.BarLateThresholdMinutes)
            : (settings.KdsWarningThresholdMinutes, settings.KdsLateThresholdMinutes);
    }

    private async Task PublishAsync(IReadOnlyList<IDomainEvent> events, CancellationToken ct)
    {
        foreach (var domainEvent in events)
        {
            switch (domainEvent)
            {
                case Domain.Preparation.Events.PreparationTicketStatusChangedDomainEvent changed:
                    await _realtime.NotifyStationAsync(
                        changed.StationId, RealtimeEvents.TicketStatusChanged, changed, ct);
                    await _realtime.NotifyRestaurantAsync(
                        changed.RestaurantId, RealtimeEvents.TicketStatusChanged, changed, ct);
                    break;

                case Domain.Ordering.Events.OrderReadyDomainEvent ready:
                    await _realtime.NotifyRestaurantAsync(
                        ready.RestaurantId, RealtimeEvents.OrderReady, ready, ct);

                    // This is the signal the waiter is actually waiting for.
                    if (ready.WaiterId is { } waiterId)
                    {
                        await _realtime.NotifyUserAsync(waiterId, RealtimeEvents.OrderReady, ready, ct);
                    }

                    break;

                case Domain.Ordering.Events.OrderStatusChangedDomainEvent changed:
                    await _realtime.NotifyRestaurantAsync(
                        changed.RestaurantId, RealtimeEvents.OrderStatusChanged, changed, ct);
                    break;

                default:
                    break;
            }
        }
    }
}
