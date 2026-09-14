using STED.RestaurantOS.Application.Authentication.Dtos;
using STED.RestaurantOS.Application.Common;

namespace STED.RestaurantOS.Application.Abstractions;

/// <summary>
/// Implemented by the infrastructure, because it needs ASP.NET Identity.
/// Declared here so the API and the use cases never depend on which identity
/// provider is in use.
/// </summary>
public interface IAuthenticationService
{
    Task<Result<LoginResponse>> LoginAsync(
        LoginRequest request,
        string? ipAddress,
        CancellationToken cancellationToken);

    /// <summary>
    /// Rotates the refresh token. Presenting one that was already used revokes
    /// the whole chain: that is a stolen-token signature, not a retry.
    /// </summary>
    Task<Result<AuthTokens>> RefreshAsync(
        string refreshToken,
        string? ipAddress,
        CancellationToken cancellationToken);

    Task<Result> LogoutAsync(string refreshToken, CancellationToken cancellationToken);

    Task<Result<AuthenticatedUser>> GetCurrentUserAsync(Guid userId, CancellationToken cancellationToken);

    Task<Result> ChangePasswordAsync(
        Guid userId,
        ChangePasswordRequest request,
        CancellationToken cancellationToken);
}
