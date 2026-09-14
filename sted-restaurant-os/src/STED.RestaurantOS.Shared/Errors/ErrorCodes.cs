namespace STED.RestaurantOS.Shared.Errors;

/// <summary>
/// Stable, machine-readable error codes returned to API clients.
/// The frontend switches on these; the human-readable message may change freely.
/// </summary>
public static class ErrorCodes
{
    // Generic
    public const string ValidationError = "VALIDATION_ERROR";
    public const string Unauthorized = "UNAUTHORIZED";
    public const string Forbidden = "FORBIDDEN";
    public const string NotFound = "NOT_FOUND";
    public const string InternalError = "INTERNAL_ERROR";
    public const string RateLimitExceeded = "RATE_LIMIT_EXCEEDED";
    public const string ConcurrencyConflict = "CONCURRENCY_CONFLICT";

    // Tenancy
    public const string TenantViolation = "TENANT_VIOLATION";

    // QR / guest
    public const string QrCodeInvalid = "QR_CODE_INVALID";

    // Floor
    public const string TableNotFound = "TABLE_NOT_FOUND";
    public const string TableAlreadyAssigned = "TABLE_ALREADY_ASSIGNED";
    public const string TableNotAssigned = "TABLE_NOT_ASSIGNED";
    public const string SessionAlreadyOpen = "SESSION_ALREADY_OPEN";
    public const string SessionClosed = "SESSION_CLOSED";
    public const string SessionHasUnpaidOrders = "SESSION_HAS_UNPAID_ORDERS";
    public const string SameWaiterTransfer = "SAME_WAITER_TRANSFER";
    public const string NotTheAssignedWaiter = "NOT_THE_ASSIGNED_WAITER";

    // Catalog
    public const string ProductUnavailable = "PRODUCT_UNAVAILABLE";
    public const string ModifierSelectionInvalid = "MODIFIER_SELECTION_INVALID";

    // Ordering
    public const string OrderNotFound = "ORDER_NOT_FOUND";
    public const string InvalidOrderStatusTransition = "INVALID_ORDER_STATUS_TRANSITION";
    public const string OrderEmpty = "ORDER_EMPTY";
    public const string OrderImmutable = "ORDER_IMMUTABLE";

    // Billing
    public const string PaymentExceedsTotal = "PAYMENT_EXCEEDS_TOTAL";
    public const string IdempotencyKeyRequired = "IDEMPOTENCY_KEY_REQUIRED";
    public const string IdempotencyKeyReused = "IDEMPOTENCY_KEY_REUSED";

    // Money
    public const string CurrencyMismatch = "CURRENCY_MISMATCH";
}
