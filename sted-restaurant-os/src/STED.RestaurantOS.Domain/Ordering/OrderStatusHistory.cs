using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Domain.Ordering;

/// <summary>
/// Append-only trace of every status change (RG-049). Written inside the same
/// transaction as the change itself, so "who marked #1025 as served?" always has
/// an answer.
/// </summary>
public sealed class OrderStatusHistory : Entity
{
    private OrderStatusHistory()
    {
    }

    private OrderStatusHistory(
        Guid id,
        Guid orderId,
        OrderStatus? previousStatus,
        OrderStatus newStatus,
        Guid? changedBy,
        DateTimeOffset changedAt,
        string? note)
        : base(id)
    {
        OrderId = orderId;
        PreviousStatus = previousStatus;
        NewStatus = newStatus;
        ChangedBy = changedBy;
        ChangedAt = changedAt;
        Note = note;
    }

    public Guid OrderId { get; private set; }

    public OrderStatus? PreviousStatus { get; private set; }

    public OrderStatus NewStatus { get; private set; }

    public Guid? ChangedBy { get; private set; }

    public DateTimeOffset ChangedAt { get; private set; }

    public string? Note { get; private set; }

    internal static OrderStatusHistory Record(
        Guid orderId,
        OrderStatus? previousStatus,
        OrderStatus newStatus,
        Guid? changedBy,
        DateTimeOffset changedAt,
        string? note)
        => new(Guid.CreateVersion7(), orderId, previousStatus, newStatus, changedBy, changedAt, note);
}
