namespace STED.RestaurantOS.Domain.Auditing;

/// <summary>
/// Business vocabulary for the audit trail. Deliberately not "UPDATE Orders":
/// an investigator reads intent, not SQL.
/// </summary>
public static class AuditActions
{
    // Authentication
    public const string LoginSuccess = "LOGIN_SUCCESS";
    public const string LoginFailed = "LOGIN_FAILED";
    public const string Logout = "LOGOUT";
    public const string PasswordChanged = "PASSWORD_CHANGED";
    public const string PasswordResetRequested = "PASSWORD_RESET_REQUESTED";
    public const string TokenRefreshed = "TOKEN_REFRESHED";
    public const string RefreshTokenReuseDetected = "REFRESH_TOKEN_REUSE_DETECTED";

    // Orders
    public const string OrderCreated = "ORDER_CREATED";
    public const string OrderConfirmed = "ORDER_CONFIRMED";
    public const string OrderItemAdded = "ORDER_ITEM_ADDED";
    public const string OrderItemRemoved = "ORDER_ITEM_REMOVED";
    public const string OrderStatusChanged = "ORDER_STATUS_CHANGED";
    public const string OrderCancelled = "ORDER_CANCELLED";
    public const string OrderDiscounted = "ORDER_DISCOUNTED";

    // Floor
    public const string TableAssigned = "TABLE_ASSIGNED";
    public const string TableTransferred = "TABLE_TRANSFERRED";
    public const string TableReleased = "TABLE_RELEASED";
    public const string SessionOpened = "SESSION_OPENED";
    public const string SessionClosed = "SESSION_CLOSED";
    public const string GuestCountChanged = "GUEST_COUNT_CHANGED";
    public const string QrRegenerated = "QR_REGENERATED";
    public const string QrDeactivated = "QR_DEACTIVATED";

    // Billing
    public const string PaymentCreated = "PAYMENT_CREATED";
    public const string PaymentCompleted = "PAYMENT_COMPLETED";
    public const string PaymentFailed = "PAYMENT_FAILED";
    public const string PaymentRefunded = "PAYMENT_REFUNDED";

    // Catalogue
    public const string ProductCreated = "PRODUCT_CREATED";
    public const string ProductModified = "PRODUCT_MODIFIED";
    public const string PriceChanged = "PRICE_CHANGED";
    public const string ProductAvailabilityChanged = "PRODUCT_AVAILABILITY_CHANGED";

    // Administration
    public const string UserCreated = "USER_CREATED";
    public const string UserDeactivated = "USER_DEACTIVATED";
    public const string RoleChanged = "ROLE_CHANGED";
    public const string PermissionChanged = "PERMISSION_CHANGED";
    public const string SettingsChanged = "SETTINGS_CHANGED";

    // Security
    public const string TenantViolationAttempt = "TENANT_VIOLATION_ATTEMPT";
    public const string RateLimitExceeded = "RATE_LIMIT_EXCEEDED";
    public const string UnauthorizedAccessAttempt = "UNAUTHORIZED_ACCESS_ATTEMPT";
}
