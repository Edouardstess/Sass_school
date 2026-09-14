namespace STED.RestaurantOS.Application.Authentication.Dtos;

/// <summary>
/// Two ways in, one result. Staff sign in with an email off shift; during
/// service they use an employee code and a PIN, because nobody types an email
/// address on a phone while carrying three plates.
/// </summary>
public sealed record LoginRequest
{
    public string? Email { get; init; }

    public string? EmployeeCode { get; init; }

    /// <summary>The password, or the PIN when signing in with an employee code.</summary>
    public required string Secret { get; init; }

    /// <summary>Needed only for employee-code sign-in, which is scoped to one venue.</summary>
    public string? RestaurantSlug { get; init; }
}

public sealed record RefreshRequest
{
    public required string RefreshToken { get; init; }
}

public sealed record AuthTokens
{
    public required string AccessToken { get; init; }

    public required string RefreshToken { get; init; }

    public required DateTimeOffset AccessTokenExpiresAt { get; init; }

    public required DateTimeOffset RefreshTokenExpiresAt { get; init; }

    public string TokenType => "Bearer";
}

public sealed record AuthenticatedUser
{
    public required Guid UserId { get; init; }

    public required string Email { get; init; }

    public required string DisplayName { get; init; }

    public Guid? StaffProfileId { get; init; }

    public Guid? RestaurantId { get; init; }

    public string? RestaurantName { get; init; }

    public required IReadOnlyList<string> Roles { get; init; }

    public required IReadOnlyList<string> Permissions { get; init; }
}

public sealed record LoginResponse
{
    public required AuthTokens Tokens { get; init; }

    public required AuthenticatedUser User { get; init; }
}

public sealed record ChangePasswordRequest
{
    public required string CurrentPassword { get; init; }

    public required string NewPassword { get; init; }
}

/// <summary>
/// What a guest gets after scanning a table's QR code: a token scoped to one
/// session, with no staff permission attached to it whatsoever.
/// </summary>
public sealed record GuestSession
{
    public required string GuestToken { get; init; }

    public required DateTimeOffset ExpiresAt { get; init; }

    public required Guid RestaurantId { get; init; }

    public required string RestaurantName { get; init; }

    public required Guid TableId { get; init; }

    public required string TableNumber { get; init; }

    public required Guid TableSessionId { get; init; }

    public required string Currency { get; init; }
}
