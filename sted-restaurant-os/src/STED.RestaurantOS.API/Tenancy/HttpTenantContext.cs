using System.Security.Claims;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Infrastructure.Identity;

namespace STED.RestaurantOS.API.Tenancy;

/// <summary>
/// Resolves the current restaurant from the authenticated principal, and from
/// nowhere else.
/// <para>
/// There is deliberately no code path here that reads a header, a route value, a
/// query string or a request body. A client that asks to act on another
/// restaurant is simply not heard: the claim in the signed token is the only
/// input. That single restriction is what turns multi-tenant isolation from a
/// hope into a property.
/// </para>
/// </summary>
public sealed class HttpTenantContext : ITenantContext
{
    private readonly IHttpContextAccessor _accessor;

    public HttpTenantContext(IHttpContextAccessor accessor) => _accessor = accessor;

    private ClaimsPrincipal? User => _accessor.HttpContext?.User;

    public Guid? RestaurantId => ReadGuid(AppClaims.RestaurantId);

    public Guid? TenantId => ReadGuid(AppClaims.TenantId);

    public Guid? StaffProfileId => ReadGuid(AppClaims.StaffProfileId);

    public Guid? UserId
    {
        get
        {
            var value = User?.FindFirstValue(ClaimTypes.NameIdentifier)
                        ?? User?.FindFirstValue("sub");

            return Guid.TryParse(value, out var id) ? id : null;
        }
    }

    /// <summary>
    /// Only the platform role lifts the tenant filter, and only for queries that
    /// opt out one at a time. It is never inferred from a missing claim.
    /// </summary>
    public bool IsPlatformAdministrator
        => User?.IsInRole(Application.Security.RoleNames.SuperAdmin) ?? false;

    public string? CorrelationId
        => _accessor.HttpContext?.Items[CorrelationIdKey] as string;

    public string? IpAddress
        => _accessor.HttpContext?.Connection.RemoteIpAddress?.ToString();

    public string? UserAgent
        => _accessor.HttpContext?.Request.Headers.UserAgent.ToString();

    public const string CorrelationIdKey = "CorrelationId";

    /// <summary>The session a guest token is bound to, if this is a guest call.</summary>
    public Guid? GuestTableSessionId => ReadGuid(AppClaims.TableSessionId);

    public Guid? GuestTableId => ReadGuid(AppClaims.TableId);

    private Guid? ReadGuid(string claimType)
    {
        var value = User?.FindFirstValue(claimType);
        return Guid.TryParse(value, out var id) ? id : null;
    }
}
