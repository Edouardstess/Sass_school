using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Diagnostics;
using Microsoft.Extensions.Logging;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Infrastructure.Tenancy;

namespace STED.RestaurantOS.Infrastructure.Persistence.Interceptors;

/// <summary>
/// The third and last line of multi-tenant defence, on the write path.
/// <para>
/// Level 1 decides the tenant from the token and nothing else. Level 2 filters
/// every read. This interceptor covers what neither can: a write. New rows get
/// the caller's restaurant stamped on them whatever the caller asked for, and an
/// attempt to modify or delete somebody else's row is refused and logged as a
/// security event rather than silently executed.
/// </para>
/// </summary>
public sealed class TenantGuardInterceptor : SaveChangesInterceptor
{
    private readonly ITenantContext _tenant;
    private readonly ILogger<TenantGuardInterceptor> _logger;

    public TenantGuardInterceptor(ITenantContext tenant, ILogger<TenantGuardInterceptor> logger)
    {
        _tenant = tenant;
        _logger = logger;
    }

    public override InterceptionResult<int> SavingChanges(
        DbContextEventData eventData,
        InterceptionResult<int> result)
    {
        Enforce(eventData.Context);
        return base.SavingChanges(eventData, result);
    }

    public override ValueTask<InterceptionResult<int>> SavingChangesAsync(
        DbContextEventData eventData,
        InterceptionResult<int> result,
        CancellationToken cancellationToken = default)
    {
        Enforce(eventData.Context);
        return base.SavingChangesAsync(eventData, result, cancellationToken);
    }

    private void Enforce(DbContext? context)
    {
        if (context is null)
        {
            return;
        }

        // Platform administration runs deliberate cross-tenant maintenance;
        // every such query opts out explicitly, one at a time.
        if (_tenant.IsPlatformAdministrator)
        {
            return;
        }

        var restaurantId = _tenant.RestaurantId;

        foreach (var entry in context.ChangeTracker.Entries())
        {
            if (entry.Entity is not ITenantEntity)
            {
                continue;
            }

            var property = entry.Property(nameof(ITenantEntity.RestaurantId));

            if (entry.State == EntityState.Added)
            {
                if (restaurantId is null)
                {
                    throw new TenantViolationException(
                        "A tenant-scoped entity cannot be created without an authenticated restaurant.");
                }

                // Whatever the caller supplied, the token decides.
                property.CurrentValue = restaurantId.Value;
                continue;
            }

            if (entry.State is not (EntityState.Modified or EntityState.Deleted))
            {
                continue;
            }

            var owner = property.CurrentValue as Guid?;

            if (owner is null || owner != restaurantId)
            {
                _logger.LogCritical(
                    "SECURITY: tenant violation. User {UserId} of restaurant {RestaurantId} attempted to " +
                    "{State} {EntityType} owned by {OwnerRestaurantId}. Correlation {CorrelationId}.",
                    _tenant.UserId,
                    restaurantId,
                    entry.State,
                    entry.Entity.GetType().Name,
                    owner,
                    _tenant.CorrelationId);

                throw new TenantViolationException(
                    "This record belongs to another restaurant.");
            }
        }
    }
}
