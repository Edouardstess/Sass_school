using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Domain.Auditing;

/// <summary>
/// Append-only evidence. There is no update method and no delete method on
/// purpose: the type itself refuses to let anyone rewrite the past (RG-081).
/// <para>
/// Uses a <see cref="long"/> identity key rather than a Guid: this is the
/// highest-volume table in the system (~500k rows per restaurant per year) and
/// it is written far more often than it is read.
/// </para>
/// </summary>
public sealed class AuditLog
{
    private AuditLog()
    {
    }

    private AuditLog(
        Guid? restaurantId,
        Guid? userId,
        string action,
        string entityName,
        string? entityId,
        string? oldValues,
        string? newValues,
        string? ipAddress,
        string? userAgent,
        string? correlationId,
        DateTimeOffset createdAt)
    {
        RestaurantId = restaurantId;
        UserId = userId;
        Action = action;
        EntityName = entityName;
        EntityId = entityId;
        OldValues = oldValues;
        NewValues = newValues;
        IpAddress = ipAddress;
        UserAgent = userAgent;
        CorrelationId = correlationId;
        CreatedAt = createdAt;
    }

    public long Id { get; private set; }

    /// <summary>Null only for platform-level events with no venue context.</summary>
    public Guid? RestaurantId { get; private set; }

    public Guid? UserId { get; private set; }

    public string Action { get; private set; } = null!;

    public string EntityName { get; private set; } = null!;

    public string? EntityId { get; private set; }

    public string? OldValues { get; private set; }

    public string? NewValues { get; private set; }

    public string? IpAddress { get; private set; }

    public string? UserAgent { get; private set; }

    /// <summary>Ties this row to the HTTP response and the Serilog entries.</summary>
    public string? CorrelationId { get; private set; }

    public DateTimeOffset CreatedAt { get; private set; }

    public static AuditLog Record(
        string action,
        string entityName,
        DateTimeOffset createdAt,
        Guid? restaurantId = null,
        Guid? userId = null,
        string? entityId = null,
        string? oldValues = null,
        string? newValues = null,
        string? ipAddress = null,
        string? userAgent = null,
        string? correlationId = null)
    {
        Guard.NotNullOrWhiteSpace(action);
        Guard.NotNullOrWhiteSpace(entityName);

        return new AuditLog(
            restaurantId,
            userId,
            Guard.MaxLength(action, 60),
            Guard.MaxLength(entityName, 60),
            entityId,
            oldValues,
            newValues,
            ipAddress,
            userAgent,
            correlationId,
            createdAt);
    }
}
