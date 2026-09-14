namespace STED.RestaurantOS.Domain.Common;

/// <summary>
/// Marks an entity as belonging to exactly one restaurant. Every such entity is
/// automatically filtered by the tenant query filter on read, and its
/// RestaurantId is enforced on write by the tenant interceptor (RG-001..RG-004).
/// </summary>
public interface ITenantEntity
{
    Guid RestaurantId { get; }
}

/// <summary>Stamped automatically by the auditing interceptor.</summary>
public interface IAuditableEntity
{
    DateTimeOffset CreatedAt { get; }

    DateTimeOffset? UpdatedAt { get; }

    void SetCreatedAt(DateTimeOffset value);

    void SetUpdatedAt(DateTimeOffset value);
}

/// <summary>Optimistic concurrency token (SQL Server <c>rowversion</c>).</summary>
public interface IHasRowVersion
{
    byte[]? RowVersion { get; }
}

/// <summary>
/// Abstracted clock. Domain code never calls DateTimeOffset.UtcNow directly:
/// time is an input, which is what makes the time-sensitive rules testable.
/// </summary>
public interface IDateTimeProvider
{
    DateTimeOffset UtcNow { get; }
}
