namespace STED.RestaurantOS.Domain.Ordering;

public enum OrderStatus
{
    /// <summary>Being composed. Not visible to any station.</summary>
    Draft = 0,

    /// <summary>Submitted by a guest, waiting for a waiter to confirm it.</summary>
    Pending = 1,

    /// <summary>Accepted by the venue. Preparation tickets exist.</summary>
    Confirmed = 2,

    InPreparation = 3,

    /// <summary>Some stations are done, others are not.</summary>
    PartiallyReady = 4,

    Ready = 5,

    Served = 6,

    Cancelled = 7,

    /// <summary>Fully paid. Terminal and immutable.</summary>
    Closed = 8,
}

public enum OrderSource
{
    /// <summary>Placed by a guest from the table QR code.</summary>
    Qr = 0,

    /// <summary>Entered by a waiter on their device.</summary>
    Waiter = 1,

    /// <summary>Entered at the counter or till.</summary>
    Counter = 2,
}

public enum OrderItemStatus
{
    Pending = 0,
    InPreparation = 1,
    Ready = 2,
    Served = 3,
    Cancelled = 4,
}
