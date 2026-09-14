namespace STED.RestaurantOS.Domain.Common;

/// <summary>
/// A fact that happened inside an aggregate. Raised in memory, dispatched by
/// the infrastructure. In-transaction handlers keep the write model consistent;
/// real-time (SignalR) publication happens only AFTER the transaction commits.
/// </summary>
public interface IDomainEvent
{
    Guid EventId { get; }

    DateTimeOffset OccurredAt { get; }
}

/// <summary>Convenience base so events only declare their payload.</summary>
public abstract record DomainEvent : IDomainEvent
{
    protected DomainEvent(DateTimeOffset occurredAt)
    {
        EventId = Guid.NewGuid();
        OccurredAt = occurredAt;
    }

    public Guid EventId { get; }

    public DateTimeOffset OccurredAt { get; }
}
