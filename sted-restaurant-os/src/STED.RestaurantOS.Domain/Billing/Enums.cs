namespace STED.RestaurantOS.Domain.Billing;

/// <summary>
/// Only <see cref="Cash"/> is implemented for the MVP. The others exist so the
/// schema, the reports and the provider abstraction are already shaped for them;
/// adding one means writing an IPaymentProvider, not migrating the database.
/// </summary>
public enum PaymentMethod
{
    Cash = 0,
    MonCash = 1,
    NatCash = 2,
    Stripe = 3,
    Card = 4,
}

public enum PaymentStatus
{
    /// <summary>Awaiting an external provider's confirmation.</summary>
    Pending = 0,

    Completed = 1,
    Failed = 2,
    Refunded = 3,
    Cancelled = 4,
}
