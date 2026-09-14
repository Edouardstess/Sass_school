using Microsoft.EntityFrameworkCore;
using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Infrastructure.Persistence;

/// <summary>
/// Drains the domain events raised during a unit of work.
/// <para>
/// The order matters and is the point: events are collected and cleared here,
/// but real-time publication happens only once the transaction has committed.
/// Announcing "order created" before the commit would put a ticket on a kitchen
/// screen for an order that a rollback then erased.
/// </para>
/// </summary>
public static class DomainEventCollector
{
    public static IReadOnlyList<IDomainEvent> Collect(DbContext context)
    {
        var roots = context.ChangeTracker
            .Entries<AggregateRoot>()
            .Where(e => e.Entity.DomainEvents.Count > 0)
            .Select(e => e.Entity)
            .ToList();

        var events = roots.SelectMany(r => r.DomainEvents).ToList();

        foreach (var root in roots)
        {
            root.ClearDomainEvents();
        }

        return events;
    }
}
