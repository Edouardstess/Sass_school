namespace STED.RestaurantOS.Domain.Common;

/// <summary>
/// Consistency boundary. Only aggregate roots are loaded and saved by
/// repositories; everything reachable from a root is modified through it,
/// which is what makes the invariants enforceable.
/// </summary>
public abstract class AggregateRoot : Entity
{
    private readonly List<IDomainEvent> _domainEvents = [];

    protected AggregateRoot(Guid id)
        : base(id)
    {
    }

    protected AggregateRoot()
    {
    }

    public IReadOnlyCollection<IDomainEvent> DomainEvents => _domainEvents.AsReadOnly();

    protected void Raise(IDomainEvent domainEvent) => _domainEvents.Add(domainEvent);

    /// <summary>Called by the dispatcher once the events have been handed off.</summary>
    public void ClearDomainEvents() => _domainEvents.Clear();
}
