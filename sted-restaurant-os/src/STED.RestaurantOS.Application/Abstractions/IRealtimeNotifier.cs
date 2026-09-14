namespace STED.RestaurantOS.Application.Abstractions;

/// <summary>
/// Pushes a signal to the screens that care.
/// <para>
/// Payloads are signals, never the source of truth: enough for an optimistic
/// badge and an identifier to reload from. A client that misses, duplicates or
/// reorders an event must still end up correct after re-reading the API.
/// </para>
/// <para>
/// The abstraction also keeps the scaling decision open: moving from one API
/// instance to several means adding a Redis backplane behind this interface,
/// with no change to any use case.
/// </para>
/// </summary>
public interface IRealtimeNotifier
{
    Task NotifyRestaurantAsync(Guid restaurantId, string eventName, object payload, CancellationToken ct);

    Task NotifyStationAsync(Guid stationId, string eventName, object payload, CancellationToken ct);

    Task NotifyTableAsync(Guid tableId, string eventName, object payload, CancellationToken ct);

    Task NotifyUserAsync(Guid userId, string eventName, object payload, CancellationToken ct);

    Task NotifyRoleAsync(Guid restaurantId, string role, string eventName, object payload, CancellationToken ct);
}

/// <summary>Event names, shared by the server and the TypeScript client.</summary>
public static class RealtimeEvents
{
    public const string OrderCreated = "order.created";
    public const string OrderConfirmed = "order.confirmed";
    public const string OrderStatusChanged = "order.status_changed";
    public const string OrderReady = "order.ready";
    public const string OrderServed = "order.served";
    public const string OrderCancelled = "order.cancelled";

    public const string TicketCreated = "ticket.created";
    public const string TicketStatusChanged = "ticket.status_changed";

    public const string PaymentCompleted = "payment.completed";

    public const string TableAssigned = "table.assigned";
    public const string TableTransferred = "table.transferred";
    public const string TableReleased = "table.released";
    public const string SessionClosed = "session.closed";
    public const string BillRequested = "bill.requested";
}
