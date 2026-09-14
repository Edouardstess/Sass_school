using System.IdentityModel.Tokens.Jwt;
using System.Security.Claims;
using System.Text;
using Microsoft.Extensions.Options;
using Microsoft.IdentityModel.Tokens;
using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Infrastructure.Identity;

public sealed record IssuedToken(string Token, DateTimeOffset ExpiresAt);

public interface IJwtTokenService
{
    IssuedToken IssueAccessToken(
        ApplicationUser user,
        string displayName,
        Guid? staffProfileId,
        Guid? tenantId,
        IReadOnlyCollection<string> roles,
        IReadOnlyCollection<string> permissions);

    /// <summary>
    /// A token for an anonymous diner. It carries the session it belongs to and
    /// nothing else — no role, no permission, a different audience — so it can
    /// only ever read its own table.
    /// </summary>
    IssuedToken IssueGuestToken(Guid restaurantId, Guid tableId, Guid tableSessionId, TimeSpan lifetime);
}

public sealed class JwtTokenService : IJwtTokenService
{
    private readonly JwtOptions _options;
    private readonly IDateTimeProvider _clock;
    private readonly SigningCredentials _credentials;

    public JwtTokenService(IOptions<JwtOptions> options, IDateTimeProvider clock)
    {
        _options = options.Value;
        _clock = clock;

        if (string.IsNullOrWhiteSpace(_options.SigningKey) || _options.SigningKey.Length < 32)
        {
            throw new InvalidOperationException(
                "The JWT signing key is missing or shorter than 32 characters. " +
                "Provide it through the environment; it must never be committed.");
        }

        var key = new SymmetricSecurityKey(Encoding.UTF8.GetBytes(_options.SigningKey));
        _credentials = new SigningCredentials(key, SecurityAlgorithms.HmacSha256);
    }

    public IssuedToken IssueAccessToken(
        ApplicationUser user,
        string displayName,
        Guid? staffProfileId,
        Guid? tenantId,
        IReadOnlyCollection<string> roles,
        IReadOnlyCollection<string> permissions)
    {
        var expiresAt = _clock.UtcNow.Add(_options.AccessTokenLifetime);

        var claims = new List<Claim>
        {
            new(JwtRegisteredClaimNames.Sub, user.Id.ToString()),
            new(JwtRegisteredClaimNames.Jti, Guid.NewGuid().ToString()),
            new(JwtRegisteredClaimNames.Email, user.Email ?? string.Empty),
            new(AppClaims.DisplayName, displayName),
        };

        if (user.RestaurantId is { } restaurantId)
        {
            claims.Add(new Claim(AppClaims.RestaurantId, restaurantId.ToString()));
        }

        if (tenantId is { } tenant)
        {
            claims.Add(new Claim(AppClaims.TenantId, tenant.ToString()));
        }

        if (staffProfileId is { } staffId)
        {
            claims.Add(new Claim(AppClaims.StaffProfileId, staffId.ToString()));
        }

        claims.AddRange(roles.Select(role => new Claim(ClaimTypes.Role, role)));
        claims.AddRange(permissions.Select(permission => new Claim(AppClaims.Permission, permission)));

        return Write(claims, _options.Audience, expiresAt);
    }

    public IssuedToken IssueGuestToken(
        Guid restaurantId,
        Guid tableId,
        Guid tableSessionId,
        TimeSpan lifetime)
    {
        var expiresAt = _clock.UtcNow.Add(lifetime);

        var claims = new List<Claim>
        {
            new(JwtRegisteredClaimNames.Sub, $"guest:{tableSessionId}"),
            new(JwtRegisteredClaimNames.Jti, Guid.NewGuid().ToString()),
            new(AppClaims.RestaurantId, restaurantId.ToString()),
            new(AppClaims.TableId, tableId.ToString()),
            new(AppClaims.TableSessionId, tableSessionId.ToString()),
        };

        return Write(claims, _options.GuestAudience, expiresAt);
    }

    private IssuedToken Write(IEnumerable<Claim> claims, string audience, DateTimeOffset expiresAt)
    {
        var token = new JwtSecurityToken(
            issuer: _options.Issuer,
            audience: audience,
            claims: claims,
            notBefore: _clock.UtcNow.UtcDateTime,
            expires: expiresAt.UtcDateTime,
            signingCredentials: _credentials);

        return new IssuedToken(new JwtSecurityTokenHandler().WriteToken(token), expiresAt);
    }
}
