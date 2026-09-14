using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Domain.Preparation.Events;

public sealed record PreparationTicketCreatedDomainEvent(
    Guid RestaurantId,
    Guid TicketId,
    Guid OrderId,
    Guid StationId,
    string TicketNumber,
    int ItemCount,
    DateTimeOffset At) : DomainEvent(At);

public sealed record PreparationTicketStatusChangedDomainEvent(
    Guid RestaurantId,
    Guid TicketId,
    Guid OrderId,
    Guid StationId,
    PreparationTicketStatus PreviousStatus,
    PreparationTicketStatus NewStatus,
    Guid? ChangedBy,
    DateTimeOffset At) : DomainEvent(At);

/// <summary>
/// Handled by the ordering module to recompute the parent order's status:
/// all tickets ready means READY, some means PARTIALLY_READY. That is what lets
/// a waiter carry the drinks out while the main course is still cooking.
/// </summary>
public sealed record PreparationTicketReadyDomainEvent(
    Guid RestaurantId,
    Guid TicketId,
    Guid OrderId,
    Guid StationId,
    DateTimeOffset At) : DomainEvent(At);
