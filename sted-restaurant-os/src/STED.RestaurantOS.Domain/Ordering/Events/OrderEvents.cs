using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Domain.Ordering.Events;

public sealed record OrderCreatedDomainEvent(
    Guid RestaurantId,
    Guid OrderId,
    string OrderNumber,
    Guid TableId,
    Guid TableSessionId,
    Guid? WaiterId,
    decimal Total,
    string Currency,
    int ItemCount,
    DateTimeOffset At) : DomainEvent(At);

/// <summary>
/// Handled in-transaction to create the preparation tickets, one per distinct
/// station in the order. Published to SignalR only after the commit succeeds.
/// </summary>
public sealed record OrderConfirmedDomainEvent(
    Guid RestaurantId,
    Guid OrderId,
    Guid TableSessionId,
    DateTimeOffset At) : DomainEvent(At);

public sealed record OrderStatusChangedDomainEvent(
    Guid RestaurantId,
    Guid OrderId,
    OrderStatus PreviousStatus,
    OrderStatus NewStatus,
    Guid? ChangedBy,
    DateTimeOffset At) : DomainEvent(At);

public sealed record OrderReadyDomainEvent(
    Guid RestaurantId,
    Guid OrderId,
    string OrderNumber,
    Guid TableId,
    Guid? WaiterId,
    DateTimeOffset At) : DomainEvent(At);

public sealed record OrderServedDomainEvent(
    Guid RestaurantId,
    Guid OrderId,
    Guid TableId,
    Guid ServedBy,
    DateTimeOffset At) : DomainEvent(At);

public sealed record OrderCancelledDomainEvent(
    Guid RestaurantId,
    Guid OrderId,
    Guid TableId,
    Guid CancelledBy,
    string Reason,
    DateTimeOffset At) : DomainEvent(At);

public sealed record OrderClosedDomainEvent(
    Guid RestaurantId,
    Guid OrderId,
    Guid TableSessionId,
    Guid ClosedBy,
    DateTimeOffset At) : DomainEvent(At);
