namespace STED.RestaurantOS.Domain.Ordering;

/// <summary>
/// The single source of truth for what may follow what. Kept as data rather than
/// scattered <c>if</c> statements so the unit test can walk every pair and assert
/// the whole matrix, and so a new status cannot be added without deciding its
/// edges.
/// </summary>
public static class OrderStatusTransitions
{
    private static readonly Dictionary<OrderStatus, OrderStatus[]> Allowed = new()
    {
        [OrderStatus.Draft] = [OrderStatus.Pending, OrderStatus.Confirmed, OrderStatus.Cancelled],
        [OrderStatus.Pending] = [OrderStatus.Confirmed, OrderStatus.Cancelled],
        [OrderStatus.Confirmed] = [OrderStatus.InPreparation, OrderStatus.PartiallyReady, OrderStatus.Ready, OrderStatus.Cancelled],
        [OrderStatus.InPreparation] = [OrderStatus.PartiallyReady, OrderStatus.Ready, OrderStatus.Cancelled],
        [OrderStatus.PartiallyReady] = [OrderStatus.Ready, OrderStatus.InPreparation, OrderStatus.Cancelled],
        [OrderStatus.Ready] = [OrderStatus.Served, OrderStatus.Cancelled],
        [OrderStatus.Served] = [OrderStatus.Closed, OrderStatus.Cancelled],
        [OrderStatus.Cancelled] = [],
        [OrderStatus.Closed] = [],
    };

    public static bool IsAllowed(OrderStatus from, OrderStatus to)
        => Allowed.TryGetValue(from, out var targets) && Array.IndexOf(targets, to) >= 0;

    public static IReadOnlyCollection<OrderStatus> From(OrderStatus status)
        => Allowed.TryGetValue(status, out var targets) ? targets : [];

    /// <summary>Terminal statuses accept no further change, ever.</summary>
    public static bool IsTerminal(OrderStatus status)
        => status is OrderStatus.Closed or OrderStatus.Cancelled;
}
