using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Diagnostics;
using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Infrastructure.Persistence.Interceptors;

/// <summary>
/// Stamps created/updated timestamps so no use case has to remember to.
/// Cross-cutting by nature, and therefore invisible to the domain.
/// </summary>
public sealed class AuditableEntityInterceptor : SaveChangesInterceptor
{
    private readonly IDateTimeProvider _clock;

    public AuditableEntityInterceptor(IDateTimeProvider clock) => _clock = clock;

    public override InterceptionResult<int> SavingChanges(
        DbContextEventData eventData,
        InterceptionResult<int> result)
    {
        Stamp(eventData.Context);
        return base.SavingChanges(eventData, result);
    }

    public override ValueTask<InterceptionResult<int>> SavingChangesAsync(
        DbContextEventData eventData,
        InterceptionResult<int> result,
        CancellationToken cancellationToken = default)
    {
        Stamp(eventData.Context);
        return base.SavingChangesAsync(eventData, result, cancellationToken);
    }

    private void Stamp(DbContext? context)
    {
        if (context is null)
        {
            return;
        }

        var now = _clock.UtcNow;

        foreach (var entry in context.ChangeTracker.Entries<IAuditableEntity>())
        {
            switch (entry.State)
            {
                case EntityState.Added:
                    entry.Entity.SetCreatedAt(now);
                    break;

                case EntityState.Modified:
                    entry.Entity.SetUpdatedAt(now);
                    break;

                default:
                    break;
            }
        }
    }
}
