using Microsoft.EntityFrameworkCore;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Ordering.Dtos;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.Ordering.Events;
using STED.RestaurantOS.Domain.Preparation.Events;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Shared.Errors;
using STED.RestaurantOS.Shared.Paging;

namespace STED.RestaurantOS.Infrastructure.Services;

/// <summary>
/// The read side and the state transitions. Queries project straight to DTOs
/// with <c>AsNoTracking</c>: a dashboard has no business materialising
/// aggregates it will never modify.
/// </summary>
public sealed partial class OrderService
{
    public async Task<Result<OrderDto>> GetOrderAsync(Guid orderId, CancellationToken ct)
    {
        var dto = await ProjectAsync(orderId, ct);

        return dto is null
            ? Error.Conflict(ErrorCodes.OrderNotFound, "This order does not exist.")
            : dto;
    }

    public async Task<PagedResult<OrderDto>> SearchAsync(
        OrderFilter filter,
        PagedRequest paging,
        CancellationToken ct)
    {
        var query = BaseQuery();

        if (filter.Status is { } status)
        {
            query = query.Where(o => o.Status == status);
        }

        if (filter.TableId is { } tableId)
        {
            query = query.Where(o => o.TableId == tableId);
        }

        if (filter.WaiterId is { } waiterId)
        {
            query = query.Where(o => o.WaiterId == waiterId);
        }

        if (filter.TableSessionId is { } sessionId)
        {
            query = query.Where(o => o.TableSessionId == sessionId);
        }

        if (filter.From is { } from)
        {
            query = query.Where(o => o.CreatedAt >= from);
        }

        if (filter.To is { } to)
        {
            query = query.Where(o => o.CreatedAt < to);
        }

        // Counted before paging, on the server. Never "load everything and count
        // in memory": a busy venue has fifty thousand orders a year.
        var totalCount = await query.CountAsync(ct);

        var ids = await query
            .OrderByDescending(o => o.CreatedAt)
            .Skip(paging.Skip)
            .Take(paging.PageSize)
            .Select(o => o.Id)
            .ToListAsync(ct);

        var items = await ProjectManyAsync(ids, ct);

        return new PagedResult<OrderDto>
        {
            Items = items,
            Page = paging.Page,
            PageSize = paging.PageSize,
            TotalCount = totalCount,
        };
    }

    public async Task<Result<OrderDto>> ConfirmAsync(Guid orderId, CancellationToken ct)
        => await MutateAsync(orderId, (order, session, now) =>
        {
            order.Confirm(session?.CurrentWaiterId, now, _tenant.StaffProfileId);

            foreach (var ticket in BuildTickets(order, now))
            {
                _db.PreparationTickets.Add(ticket);
            }
        }, ct);

    public async Task<Result<OrderDto>> ServeAsync(Guid orderId, CancellationToken ct)
    {
        if (_tenant.StaffProfileId is not { } staffId)
        {
            return Error.Forbidden("Only a staff member can serve an order.");
        }

        return await MutateAsync(orderId, (order, _, now) => order.Serve(staffId, now), ct);
    }

    public async Task<Result<OrderDto>> CancelAsync(
        Guid orderId,
        CancelOrderRequest request,
        CancellationToken ct)
    {
        if (_tenant.StaffProfileId is not { } staffId)
        {
            return Error.Forbidden("Only a staff member can cancel an order.");
        }

        return await MutateAsync(orderId, (order, _, now) => order.Cancel(staffId, request.Reason, now), ct);
    }

    public async Task<Result<OrderDto>> ApplyDiscountAsync(
        Guid orderId,
        ApplyDiscountRequest request,
        CancellationToken ct)
    {
        if (_tenant.RestaurantId is not { } restaurantId)
        {
            return Error.Unauthorized("No restaurant in context.");
        }

        if (_tenant.StaffProfileId is not { } staffId)
        {
            return Error.Forbidden("Only a staff member can apply a discount.");
        }

        var settings = await LoadSettingsAsync(restaurantId, ct);

        if (settings is null)
        {
            return Error.NotFound("Restaurant not found.");
        }

        return await MutateAsync(orderId, (order, _, now) => order.ApplyDiscount(
            Percentage.Of(request.Percentage),
            settings.MaxDiscountPercentage,
            request.Reason,
            staffId,
            now), ct);
    }

    public async Task<IReadOnlyList<OrderStatusHistoryDto>> GetHistoryAsync(
        Guid orderId,
        CancellationToken ct)
        => await _db.OrderStatusHistory
            .Where(h => h.OrderId == orderId)
            .OrderBy(h => h.ChangedAt)
            .Select(h => new OrderStatusHistoryDto(
                h.PreviousStatus,
                h.NewStatus,
                h.ChangedBy,
                _db.StaffProfiles
                    .Where(s => s.Id == h.ChangedBy)
                    .Select(s => s.DisplayName)
                    .FirstOrDefault(),
                h.ChangedAt,
                h.Note))
            .ToListAsync(ct);

    /// <summary>
    /// Loads the order, applies a state change, commits, then publishes.
    /// <para>
    /// Every transition goes through here so that the "commit first, announce
    /// afterwards" rule has exactly one implementation to get right.
    /// </para>
    /// </summary>
    private async Task<Result<OrderDto>> MutateAsync(
        Guid orderId,
        Action<Order, Domain.Floor.TableSession?, DateTimeOffset> change,
        CancellationToken ct)
        => await _uow.ExecuteInTransactionAsync<Result<OrderDto>>(async token =>
        {
            var order = await _db.Orders
                .Include(o => o.Items)
                .FirstOrDefaultAsync(o => o.Id == orderId, token);

            if (order is null)
            {
                return Error.Conflict(ErrorCodes.OrderNotFound, "This order does not exist.");
            }

            var session = await _db.TableSessions
                .Include(s => s.Assignments)
                .FirstOrDefaultAsync(s => s.Id == order.TableSessionId, token);

            change(order, session, _clock.UtcNow);

            var events = await _uow.CommitTransactionAsync(token);
            await PublishAsync(events, token);

            var dto = await ProjectAsync(orderId, token);

            return dto is null
                ? Error.Conflict(ErrorCodes.OrderNotFound, "This order does not exist.")
                : dto;
        }, ct);

    /// <summary>
    /// Turns committed domain events into real-time signals.
    /// <para>
    /// Called only after the transaction succeeded. Payloads stay small: a
    /// screen uses them to flash a badge, then reloads the real state from the
    /// API, so a dropped or duplicated event cannot corrupt what is displayed.
    /// </para>
    /// </summary>
    private async Task PublishAsync(IReadOnlyList<IDomainEvent> events, CancellationToken ct)
    {
        foreach (var domainEvent in events)
        {
            switch (domainEvent)
            {
                case OrderCreatedDomainEvent created:
                    await _realtime.NotifyRestaurantAsync(
                        created.RestaurantId, RealtimeEvents.OrderCreated, created, ct);
                    await _realtime.NotifyTableAsync(
                        created.TableId, RealtimeEvents.OrderCreated, created, ct);

                    if (created.WaiterId is { } waiter)
                    {
                        await _realtime.NotifyUserAsync(waiter, RealtimeEvents.OrderCreated, created, ct);
                    }

                    break;

                case OrderStatusChangedDomainEvent changed:
                    await _realtime.NotifyRestaurantAsync(
                        changed.RestaurantId, RealtimeEvents.OrderStatusChanged, changed, ct);
                    break;

                case OrderReadyDomainEvent ready:
                    await _realtime.NotifyRestaurantAsync(
                        ready.RestaurantId, RealtimeEvents.OrderReady, ready, ct);

                    // The waiter is the one who has to act on this.
                    if (ready.WaiterId is { } readyWaiter)
                    {
                        await _realtime.NotifyUserAsync(readyWaiter, RealtimeEvents.OrderReady, ready, ct);
                    }

                    break;

                case OrderServedDomainEvent served:
                    await _realtime.NotifyRestaurantAsync(
                        served.RestaurantId, RealtimeEvents.OrderServed, served, ct);
                    await _realtime.NotifyTableAsync(served.TableId, RealtimeEvents.OrderServed, served, ct);
                    break;

                case OrderCancelledDomainEvent cancelled:
                    await _realtime.NotifyRestaurantAsync(
                        cancelled.RestaurantId, RealtimeEvents.OrderCancelled, cancelled, ct);
                    await _realtime.NotifyTableAsync(
                        cancelled.TableId, RealtimeEvents.OrderCancelled, cancelled, ct);
                    break;

                case PreparationTicketCreatedDomainEvent ticket:
                    // Straight to the station that has to cook it.
                    await _realtime.NotifyStationAsync(
                        ticket.StationId, RealtimeEvents.TicketCreated, ticket, ct);
                    break;

                case PreparationTicketStatusChangedDomainEvent ticketChanged:
                    await _realtime.NotifyStationAsync(
                        ticketChanged.StationId, RealtimeEvents.TicketStatusChanged, ticketChanged, ct);
                    await _realtime.NotifyRestaurantAsync(
                        ticketChanged.RestaurantId, RealtimeEvents.TicketStatusChanged, ticketChanged, ct);
                    break;

                default:
                    break;
            }
        }
    }

    private IQueryable<Order> BaseQuery() => _db.Orders.AsNoTracking();

    private async Task<OrderDto?> ProjectAsync(Guid orderId, CancellationToken ct)
        => (await ProjectManyAsync([orderId], ct)).FirstOrDefault();

    private async Task<IReadOnlyList<OrderDto>> ProjectManyAsync(
        IReadOnlyList<Guid> orderIds,
        CancellationToken ct)
    {
        if (orderIds.Count == 0)
        {
            return [];
        }

        var now = _clock.UtcNow;

        var rows = await _db.Orders
            .AsNoTracking()
            .Where(o => orderIds.Contains(o.Id))
            .Select(o => new
            {
                Order = o,
                TableNumber = _db.RestaurantTables
                    .Where(t => t.Id == o.TableId)
                    .Select(t => t.Number)
                    .FirstOrDefault(),
                WaiterName = _db.StaffProfiles
                    .Where(s => s.Id == o.WaiterId)
                    .Select(s => s.DisplayName)
                    .FirstOrDefault(),
                ServedByName = _db.StaffProfiles
                    .Where(s => s.Id == o.ServedBy)
                    .Select(s => s.DisplayName)
                    .FirstOrDefault(),
                Items = o.Items.Select(i => new
                {
                    i.Id,
                    i.ProductId,
                    i.ProductNameSnapshot,
                    i.Quantity,
                    UnitPrice = i.UnitPriceSnapshot.Amount,
                    LineTotal = i.LineTotal.Amount,
                    i.StationCodeSnapshot,
                    i.Status,
                    i.Notes,
                    Modifiers = i.Modifiers.Select(m => m.OptionNameSnapshot).ToList(),
                }).ToList(),
            })
            .ToListAsync(ct);

        // Order preserved as requested, so paging stays stable.
        var byId = rows.ToDictionary(r => r.Order.Id);

        return orderIds
            .Where(byId.ContainsKey)
            .Select(id => byId[id])
            .Select(row => new OrderDto
            {
                Id = row.Order.Id,
                OrderNumber = row.Order.OrderNumber.Value,
                TableId = row.Order.TableId,
                TableNumber = row.TableNumber ?? "?",
                TableSessionId = row.Order.TableSessionId,
                WaiterId = row.Order.WaiterId,
                WaiterName = row.WaiterName,
                Status = row.Order.Status,
                Source = row.Order.Source,
                Subtotal = row.Order.Subtotal.Amount,
                TaxAmount = row.Order.TaxAmount.Amount,
                DiscountAmount = row.Order.DiscountAmount.Amount,
                ServiceChargeAmount = row.Order.ServiceChargeAmount.Amount,
                Total = row.Order.Total.Amount,
                Currency = row.Order.Currency,
                Notes = row.Order.Notes,
                CreatedAt = row.Order.CreatedAt,
                ConfirmedAt = row.Order.ConfirmedAt,
                ReadyAt = row.Order.ReadyAt,
                ServedAt = row.Order.ServedAt,
                ServedBy = row.Order.ServedBy,
                ServedByName = row.ServedByName,
                WaitingMinutes = row.Order.ConfirmedAt is null
                    ? null
                    : (int)(now - row.Order.ConfirmedAt.Value).TotalMinutes,
                Items = row.Items.Select(i => new OrderItemDto(
                    i.Id,
                    i.ProductId,
                    i.ProductNameSnapshot,
                    i.Quantity,
                    i.UnitPrice,
                    i.LineTotal,
                    i.StationCodeSnapshot,
                    i.Status,
                    i.Notes,
                    i.Modifiers)).ToList(),
            })
            .ToList();
    }
}
