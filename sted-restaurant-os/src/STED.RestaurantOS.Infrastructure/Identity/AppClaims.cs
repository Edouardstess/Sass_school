namespace STED.RestaurantOS.Infrastructure.Identity;

/// <summary>
/// Claim names shared by the token issuer and every reader of a token.
/// Kept in one place so a rename cannot silently break authorisation.
/// </summary>
public static class AppClaims
{
    public const string RestaurantId = "restaurant_id";
    public const string TenantId = "tenant_id";
    public const string StaffProfileId = "staff_id";
    public const string DisplayName = "display_name";
    public const string Permission = "perm";

    // Guest tokens only.
    public const string TableSessionId = "session_id";
    public const string TableId = "table_id";
}
