namespace STED.RestaurantOS.Domain.Common;

/// <summary>
/// The restaurant the current request is acting on.
/// <para>
/// Populated from the authenticated principal and from nothing else: never from
/// a header, a route value, a query string or a request body. That single rule
/// is what makes multi-tenant isolation enforceable rather than hopeful
/// (RG-002).
/// </para>
/// </summary>
public interface ITenantContext
{
    /// <summary>Null for platform administrators and for unauthenticated calls.</summary>
    Guid? RestaurantId { get; }

    Guid? TenantId { get; }

    /// <summary>The acting staff profile, when there is one.</summary>
    Guid? StaffProfileId { get; }

    Guid? UserId { get; }

    /// <summary>
    /// True when the caller is allowed to read across restaurants (platform
    /// administration). Query filters are still applied; such queries must opt
    /// out explicitly, one query at a time.
    /// </summary>
    bool IsPlatformAdministrator { get; }

    string? CorrelationId { get; }

    string? IpAddress { get; }

    string? UserAgent { get; }
}
