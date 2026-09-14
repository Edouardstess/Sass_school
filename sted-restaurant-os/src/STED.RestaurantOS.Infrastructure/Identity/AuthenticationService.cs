using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Authentication.Dtos;
using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Domain.Auditing;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Infrastructure.Persistence;

namespace STED.RestaurantOS.Infrastructure.Identity;

public sealed class AuthenticationService : IAuthenticationService
{
    // Deliberately identical for a wrong email, a wrong password and a
    // deactivated account: telling them apart is free reconnaissance.
    private const string InvalidCredentials = "Email, employee code or password is incorrect.";

    private readonly UserManager<ApplicationUser> _users;
    private readonly AppDbContext _db;
    private readonly IJwtTokenService _tokens;
    private readonly IPermissionResolver _permissions;
    private readonly IDateTimeProvider _clock;
    private readonly JwtOptions _options;
    private readonly ILogger<AuthenticationService> _logger;

    public AuthenticationService(
        UserManager<ApplicationUser> users,
        AppDbContext db,
        IJwtTokenService tokens,
        IPermissionResolver permissions,
        IDateTimeProvider clock,
        IOptions<JwtOptions> options,
        ILogger<AuthenticationService> logger)
    {
        _users = users;
        _db = db;
        _tokens = tokens;
        _permissions = permissions;
        _clock = clock;
        _options = options.Value;
        _logger = logger;
    }

    public async Task<Result<LoginResponse>> LoginAsync(
        LoginRequest request,
        string? ipAddress,
        CancellationToken cancellationToken)
    {
        var user = string.IsNullOrWhiteSpace(request.EmployeeCode)
            ? await FindByEmailAsync(request.Email, cancellationToken)
            : await FindByEmployeeCodeAsync(request.EmployeeCode!, request.RestaurantSlug, cancellationToken);

        if (user is null)
        {
            await AuditAsync(AuditActions.LoginFailed, null, null, ipAddress, "unknown account", cancellationToken);
            return Error.Unauthorized(InvalidCredentials);
        }

        if (await _users.IsLockedOutAsync(user))
        {
            await AuditAsync(AuditActions.LoginFailed, user.Id, user.RestaurantId, ipAddress, "locked out", cancellationToken);
            return Error.Unauthorized("This account is temporarily locked. Try again in a few minutes.");
        }

        var secretIsValid = string.IsNullOrWhiteSpace(request.EmployeeCode)
            ? await _users.CheckPasswordAsync(user, request.Secret)
            : VerifyPin(user, request.Secret);

        if (!secretIsValid)
        {
            // Counts towards lockout whichever path was used: a four-digit PIN
            // would otherwise be brute-forced in an afternoon.
            await _users.AccessFailedAsync(user);
            await AuditAsync(AuditActions.LoginFailed, user.Id, user.RestaurantId, ipAddress, "bad secret", cancellationToken);
            return Error.Unauthorized(InvalidCredentials);
        }

        if (!user.IsActive)
        {
            await AuditAsync(AuditActions.LoginFailed, user.Id, user.RestaurantId, ipAddress, "inactive account", cancellationToken);
            return Error.Unauthorized(InvalidCredentials);
        }

        await _users.ResetAccessFailedCountAsync(user);

        var profile = await LoadProfileAsync(user, cancellationToken);
        var tokens = await IssueTokensAsync(user, profile, ipAddress, cancellationToken);

        user.LastLoginAt = _clock.UtcNow;
        await AuditAsync(AuditActions.LoginSuccess, user.Id, user.RestaurantId, ipAddress, null, cancellationToken);
        await _db.SaveChangesAsync(cancellationToken);

        return new LoginResponse { Tokens = tokens, User = profile.ToDto(user) };
    }

    public async Task<Result<AuthTokens>> RefreshAsync(
        string refreshToken,
        string? ipAddress,
        CancellationToken cancellationToken)
    {
        var hash = RefreshToken.Hash(refreshToken);

        var stored = await _db.RefreshTokens
            .FirstOrDefaultAsync(t => t.TokenHash == hash, cancellationToken);

        if (stored is null)
        {
            return Error.Unauthorized("This session is no longer valid. Please sign in again.");
        }

        // A client never presents a token it has already exchanged. Seeing one
        // means a copy is in circulation, so every session for this user dies.
        if (stored.IsRevoked)
        {
            await RevokeAllForUserAsync(stored.UserId, "Refresh token reuse detected", cancellationToken);

            _logger.LogWarning(
                "SECURITY: refresh token reuse detected for user {UserId} from {IpAddress}. " +
                "All sessions revoked.",
                stored.UserId,
                ipAddress);

            await AuditAsync(
                AuditActions.RefreshTokenReuseDetected,
                stored.UserId,
                null,
                ipAddress,
                "token replayed after rotation",
                cancellationToken);

            await _db.SaveChangesAsync(cancellationToken);
            return Error.Unauthorized("This session is no longer valid. Please sign in again.");
        }

        if (!stored.IsActiveAt(_clock.UtcNow))
        {
            return Error.Unauthorized("This session has expired. Please sign in again.");
        }

        var user = await _users.FindByIdAsync(stored.UserId.ToString());

        if (user is null || !user.IsActive)
        {
            stored.Revoke(_clock.UtcNow, "Account unavailable");
            await _db.SaveChangesAsync(cancellationToken);
            return Error.Unauthorized(InvalidCredentials);
        }

        var profile = await LoadProfileAsync(user, cancellationToken);
        var issued = await IssueTokensAsync(user, profile, ipAddress, cancellationToken, rotating: stored);

        await AuditAsync(AuditActions.TokenRefreshed, user.Id, user.RestaurantId, ipAddress, null, cancellationToken);
        await _db.SaveChangesAsync(cancellationToken);

        return issued;
    }

    public async Task<Result> LogoutAsync(string refreshToken, CancellationToken cancellationToken)
    {
        var hash = RefreshToken.Hash(refreshToken);

        var stored = await _db.RefreshTokens
            .FirstOrDefaultAsync(t => t.TokenHash == hash, cancellationToken);

        // Signing out something that is already gone is a success, not an error.
        if (stored is null || stored.IsRevoked)
        {
            return Result.Success();
        }

        stored.Revoke(_clock.UtcNow, "Signed out");
        await AuditAsync(AuditActions.Logout, stored.UserId, null, null, null, cancellationToken);
        await _db.SaveChangesAsync(cancellationToken);

        return Result.Success();
    }

    public async Task<Result<AuthenticatedUser>> GetCurrentUserAsync(
        Guid userId,
        CancellationToken cancellationToken)
    {
        var user = await _users.FindByIdAsync(userId.ToString());

        if (user is null || !user.IsActive)
        {
            return Error.Unauthorized(InvalidCredentials);
        }

        var profile = await LoadProfileAsync(user, cancellationToken);
        return profile.ToDto(user);
    }

    public async Task<Result> ChangePasswordAsync(
        Guid userId,
        ChangePasswordRequest request,
        CancellationToken cancellationToken)
    {
        var user = await _users.FindByIdAsync(userId.ToString());

        if (user is null)
        {
            return Error.Unauthorized(InvalidCredentials);
        }

        var outcome = await _users.ChangePasswordAsync(user, request.CurrentPassword, request.NewPassword);

        if (!outcome.Succeeded)
        {
            return Error.Validation(string.Join(" ", outcome.Errors.Select(e => e.Description)));
        }

        // A password change ends every other session: that is the point of
        // changing it after a suspected compromise.
        await RevokeAllForUserAsync(userId, "Password changed", cancellationToken);
        await AuditAsync(AuditActions.PasswordChanged, userId, user.RestaurantId, null, null, cancellationToken);
        await _db.SaveChangesAsync(cancellationToken);

        return Result.Success();
    }

    private async Task<ApplicationUser?> FindByEmailAsync(string? email, CancellationToken cancellationToken)
    {
        _ = cancellationToken;
        return string.IsNullOrWhiteSpace(email) ? null : await _users.FindByEmailAsync(email);
    }

    private async Task<ApplicationUser?> FindByEmployeeCodeAsync(
        string employeeCode,
        string? restaurantSlug,
        CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(restaurantSlug))
        {
            return null;
        }

        var code = employeeCode.Trim().ToUpperInvariant();
        var slug = restaurantSlug.Trim().ToLowerInvariant();

        // IgnoreQueryFilters is required and safe here: at sign-in there is no
        // authenticated restaurant yet, so the tenant filter would match nothing.
        // The query is still pinned to one venue by its slug.
        var userId = await _db.StaffProfiles
            .IgnoreQueryFilters()
            .Where(p => p.IsActive)
            .Join(
                _db.Restaurants.IgnoreQueryFilters().Where(r => r.IsActive),
                p => p.RestaurantId,
                r => r.Id,
                (p, r) => new { Profile = p, Restaurant = r })
            .Where(x => x.Restaurant.Slug == STED.RestaurantOS.Domain.ValueObjects.Slug.Of(slug)
                        && x.Profile.EmployeeCode == STED.RestaurantOS.Domain.ValueObjects.EmployeeCode.Of(code))
            .Select(x => x.Profile.UserId)
            .FirstOrDefaultAsync(cancellationToken);

        return userId == Guid.Empty ? null : await _users.FindByIdAsync(userId.ToString());
    }

    private bool VerifyPin(ApplicationUser user, string pin)
    {
        if (string.IsNullOrWhiteSpace(user.PinHash))
        {
            return false;
        }

        var outcome = _users.PasswordHasher.VerifyHashedPassword(user, user.PinHash, pin);
        return outcome is PasswordVerificationResult.Success
            or PasswordVerificationResult.SuccessRehashNeeded;
    }

    private async Task<UserProfileData> LoadProfileAsync(
        ApplicationUser user,
        CancellationToken cancellationToken)
    {
        var roles = await _users.GetRolesAsync(user);
        var permissions = await _permissions.ResolveAsync(user.Id, cancellationToken);

        var staff = await _db.StaffProfiles
            .IgnoreQueryFilters()
            .Where(p => p.UserId == user.Id)
            .Select(p => new { p.Id, p.DisplayName, p.RestaurantId })
            .FirstOrDefaultAsync(cancellationToken);

        var restaurant = user.RestaurantId is null
            ? null
            : await _db.Restaurants
                .IgnoreQueryFilters()
                .Where(r => r.Id == user.RestaurantId)
                .Select(r => new { r.Name, r.TenantId })
                .FirstOrDefaultAsync(cancellationToken);

        return new UserProfileData(
            staff?.Id,
            staff?.DisplayName ?? user.Email ?? user.UserName ?? "Unknown",
            restaurant?.Name,
            restaurant?.TenantId,
            roles.ToList(),
            permissions);
    }

    private async Task<AuthTokens> IssueTokensAsync(
        ApplicationUser user,
        UserProfileData profile,
        string? ipAddress,
        CancellationToken cancellationToken,
        RefreshToken? rotating = null)
    {
        _ = cancellationToken;

        var access = _tokens.IssueAccessToken(
            user,
            profile.DisplayName,
            profile.StaffProfileId,
            profile.TenantId,
            profile.Roles,
            profile.Permissions);

        var (clear, refresh) = RefreshToken.Issue(
            user.Id,
            _clock.UtcNow,
            _options.RefreshTokenLifetime,
            ipAddress);

        _db.RefreshTokens.Add(refresh);

        // Rotation: the token just used is retired and points at its successor,
        // which is what makes replay detectable.
        rotating?.Revoke(_clock.UtcNow, "Rotated", refresh.Id);

        return new AuthTokens
        {
            AccessToken = access.Token,
            RefreshToken = clear,
            AccessTokenExpiresAt = access.ExpiresAt,
            RefreshTokenExpiresAt = refresh.ExpiresAt,
        };
    }

    private async Task RevokeAllForUserAsync(Guid userId, string reason, CancellationToken cancellationToken)
    {
        var live = await _db.RefreshTokens
            .Where(t => t.UserId == userId && t.RevokedAt == null)
            .ToListAsync(cancellationToken);

        foreach (var token in live)
        {
            token.Revoke(_clock.UtcNow, reason);
        }
    }

    private async Task AuditAsync(
        string action,
        Guid? userId,
        Guid? restaurantId,
        string? ipAddress,
        string? detail,
        CancellationToken cancellationToken)
    {
        _ = cancellationToken;

        _db.AuditLogs.Add(AuditLog.Record(
            action,
            nameof(ApplicationUser),
            _clock.UtcNow,
            restaurantId,
            userId,
            userId?.ToString(),
            newValues: detail,
            ipAddress: ipAddress));
    }

    private sealed record UserProfileData(
        Guid? StaffProfileId,
        string DisplayName,
        string? RestaurantName,
        Guid? TenantId,
        IReadOnlyList<string> Roles,
        IReadOnlyList<string> Permissions)
    {
        public AuthenticatedUser ToDto(ApplicationUser user) => new()
        {
            UserId = user.Id,
            Email = user.Email ?? string.Empty,
            DisplayName = DisplayName,
            StaffProfileId = StaffProfileId,
            RestaurantId = user.RestaurantId,
            RestaurantName = RestaurantName,
            Roles = Roles,
            Permissions = Permissions,
        };
    }
}
