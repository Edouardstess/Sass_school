namespace STED.RestaurantOS.Infrastructure.Identity;

public sealed class JwtOptions
{
    public const string SectionName = "Jwt";

    /// <summary>
    /// Supplied by the environment, never by a file in the repository.
    /// Must be at least 32 bytes; startup refuses to run otherwise.
    /// </summary>
    public string SigningKey { get; set; } = string.Empty;

    public string Issuer { get; set; } = "sted-restaurant-os";

    public string Audience { get; set; } = "sted-restaurant-os-api";

    /// <summary>
    /// Short on purpose. A stolen access token is useful for minutes, not days;
    /// continuity comes from the refresh token, which can be revoked.
    /// </summary>
    public int AccessTokenMinutes { get; set; } = 15;

    public int RefreshTokenDays { get; set; } = 14;

    /// <summary>Audience of guest tokens, kept distinct so staff endpoints reject them outright.</summary>
    public string GuestAudience { get; set; } = "sted-restaurant-os-guest";

    public int GuestTokenHours { get; set; } = 4;

    public TimeSpan AccessTokenLifetime => TimeSpan.FromMinutes(AccessTokenMinutes);

    public TimeSpan RefreshTokenLifetime => TimeSpan.FromDays(RefreshTokenDays);

    public TimeSpan GuestTokenLifetime => TimeSpan.FromHours(GuestTokenHours);
}
