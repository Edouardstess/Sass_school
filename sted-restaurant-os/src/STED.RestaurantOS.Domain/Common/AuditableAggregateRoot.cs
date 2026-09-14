namespace STED.RestaurantOS.Domain.Common;

/// <summary>
/// Aggregate root whose creation and last modification timestamps are stamped
/// by the persistence layer, so no use case has to remember to do it.
/// </summary>
public abstract class AuditableAggregateRoot : AggregateRoot, IAuditableEntity
{
    protected AuditableAggregateRoot(Guid id)
        : base(id)
    {
    }

    protected AuditableAggregateRoot()
    {
    }

    public DateTimeOffset CreatedAt { get; private set; }

    public DateTimeOffset? UpdatedAt { get; private set; }

    public void SetCreatedAt(DateTimeOffset value) => CreatedAt = value;

    public void SetUpdatedAt(DateTimeOffset value) => UpdatedAt = value;
}
