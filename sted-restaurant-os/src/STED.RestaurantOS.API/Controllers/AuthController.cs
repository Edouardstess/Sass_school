using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.RateLimiting;
using STED.RestaurantOS.API.Configuration;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Authentication.Dtos;

namespace STED.RestaurantOS.API.Controllers;

[Route("api/v1/auth")]
public sealed class AuthController : ApiControllerBase
{
    private readonly IAuthenticationService _auth;

    public AuthController(IAuthenticationService auth) => _auth = auth;

    /// <summary>
    /// Signs in with either an email and password, or an employee code and PIN
    /// plus the venue's slug.
    /// </summary>
    [HttpPost("login")]
    [AllowAnonymous]
    [EnableRateLimiting(RateLimitPolicies.Authentication)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status401Unauthorized)]
    public async Task<IActionResult> Login(
        [FromBody] LoginRequest request,
        CancellationToken cancellationToken)
        => Ok(await _auth.LoginAsync(request, HttpContext.Connection.RemoteIpAddress?.ToString(), cancellationToken));

    /// <summary>
    /// Exchanges a refresh token for a new pair. The old token is revoked;
    /// presenting it again revokes every session for that account.
    /// </summary>
    [HttpPost("refresh")]
    [AllowAnonymous]
    [EnableRateLimiting(RateLimitPolicies.Authentication)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status401Unauthorized)]
    public async Task<IActionResult> Refresh(
        [FromBody] RefreshRequest request,
        CancellationToken cancellationToken)
        => Ok(await _auth.RefreshAsync(
            request.RefreshToken,
            HttpContext.Connection.RemoteIpAddress?.ToString(),
            cancellationToken));

    [HttpPost("logout")]
    [Authorize]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    public async Task<IActionResult> Logout(
        [FromBody] RefreshRequest request,
        CancellationToken cancellationToken)
        => Ok(await _auth.LogoutAsync(request.RefreshToken, cancellationToken));

    /// <summary>
    /// Who am I, and what am I allowed to do. The frontend uses the permission
    /// list to decide what to render — the server still checks every call.
    /// </summary>
    [HttpGet("me")]
    [Authorize]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    public async Task<IActionResult> Me(CancellationToken cancellationToken)
    {
        var userId = CurrentUserId();

        return userId is null
            ? Unauthorized(ApiResponse.Fail(
                "Not authenticated.",
                Shared.Errors.ErrorCodes.Unauthorized,
                CorrelationId))
            : Ok(await _auth.GetCurrentUserAsync(userId.Value, cancellationToken));
    }

    [HttpPost("change-password")]
    [Authorize]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    public async Task<IActionResult> ChangePassword(
        [FromBody] ChangePasswordRequest request,
        CancellationToken cancellationToken)
    {
        var userId = CurrentUserId();

        return userId is null
            ? Unauthorized(ApiResponse.Fail(
                "Not authenticated.",
                Shared.Errors.ErrorCodes.Unauthorized,
                CorrelationId))
            : Ok(await _auth.ChangePasswordAsync(userId.Value, request, cancellationToken));
    }

    private Guid? CurrentUserId()
    {
        var value = User.FindFirstValue(ClaimTypes.NameIdentifier) ?? User.FindFirstValue("sub");
        return Guid.TryParse(value, out var id) ? id : null;
    }
}
