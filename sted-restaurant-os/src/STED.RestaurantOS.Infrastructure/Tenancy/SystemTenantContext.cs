using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Infrastructure.Tenancy;

/// <summary>
/// Tenant context for work that runs outside an HTTP request: migrations,
/// seeding, background jobs.
/// <para>
/// There is no ambient "no tenant" mode. A background job states which
/// restaurant it acts for, or it declares itself platform-level explicitly.
/// Making that a decision rather than a default is what stops a scheduled task
/// from quietly reading everything.
/// </para>
/// </summary>
public sealed class SystemTenantContext : ITenantContext
{
    private SystemTenantContext(Guid? restaurantId, bool isPlatformAdministrator)
    {
        RestaurantId = restaurantId;
        IsPlatformAdministrator = isPlatformAdministrator;
    }

    public Guid? RestaurantId { get; }

    public Guid? TenantId => null;

    public Guid? StaffProfileId => null;

    public Guid? UserId => null;

    public bool IsPlatformAdministrator { get; }

    public string? CorrelationId => "system";

    public string? IpAddress => null;

    public string? UserAgent => "system";

    /// <summary>Scoped to one restaurant: the normal case for a background job.</summary>
    public static SystemTenantContext For(Guid restaurantId) => new(restaurantId, false);

    /// <summary>
    /// Cross-tenant. Used by migrations and seeding only; every call site is a
    /// deliberate, reviewable decision.
    /// </summary>
    public static SystemTenantContext Platform() => new(null, true);
}
