using System.Security.Cryptography;
using Microsoft.AspNetCore.Identity;
using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Infrastructure.Identity;

/// <summary>
/// The authentication account. Deliberately thin: everything operational —
/// display name, employee code, what this person does — lives on
/// <c>StaffProfile</c> in the domain, so the business model never depends on the
/// identity provider's schema.
/// </summary>
public sealed class ApplicationUser : IdentityUser<Guid>
{
    /// <summary>Null for platform administrators, who belong to no single venue.</summary>
    public Guid? RestaurantId { get; set; }

    public bool IsActive { get; set; } = true;

    public DateTimeOffset CreatedAt { get; set; }

    public DateTimeOffset? LastLoginAt { get; set; }

    /// <summary>
    /// Hashed PIN for the shift sign-in path. Stored with the same hasher as the
    /// password; a four-digit PIN is weak by nature, which is why it only works
    /// together with an employee code scoped to one venue, and why the account
    /// lockout applies to it exactly as it does to the password.
    /// </summary>
    public string? PinHash { get; set; }
}

public sealed class ApplicationRole : IdentityRole<Guid>
{
    public ApplicationRole()
    {
    }

    public ApplicationRole(string roleName)
        : base(roleName)
    {
    }

    /// <summary>Null for the built-in roles shared by every venue.</summary>
    public Guid? RestaurantId { get; set; }

    public string? Description { get; set; }
}

/// <summary>
/// A refresh token, stored as a hash.
/// <para>
/// Rotation plus reuse detection: each refresh issues a new token and revokes
/// the old one. If a revoked token is ever presented again, the entire chain is
/// revoked — a legitimate client never replays a token it has already exchanged,
/// so that pattern means a copy is in circulation.
/// </para>
/// </summary>
public sealed class RefreshToken
{
    public const int TokenBytes = 32;

    private RefreshToken()
    {
    }

    private RefreshToken(
        Guid id,
        Guid userId,
        byte[] tokenHash,
        DateTimeOffset createdAt,
        DateTimeOffset expiresAt,
        string? createdByIp)
    {
        Id = id;
        UserId = userId;
        TokenHash = tokenHash;
        CreatedAt = createdAt;
        ExpiresAt = expiresAt;
        CreatedByIp = createdByIp;
    }

    public Guid Id { get; private set; }

    public Guid UserId { get; private set; }

    public byte[] TokenHash { get; private set; } = [];

    public DateTimeOffset CreatedAt { get; private set; }

    public DateTimeOffset ExpiresAt { get; private set; }

    public DateTimeOffset? RevokedAt { get; private set; }

    public Guid? ReplacedByTokenId { get; private set; }

    public string? CreatedByIp { get; private set; }

    public string? RevokedReason { get; private set; }

    public bool IsRevoked => RevokedAt is not null;

    public bool IsActiveAt(DateTimeOffset at) => !IsRevoked && ExpiresAt > at;

    /// <summary>Returns the clear token (shown once) and the row that remembers it.</summary>
    public static (string ClearToken, RefreshToken Token) Issue(
        Guid userId,
        DateTimeOffset now,
        TimeSpan lifetime,
        string? ipAddress)
    {
        Guard.NotEmpty(userId);

        var bytes = RandomNumberGenerator.GetBytes(TokenBytes);
        var clear = Convert.ToBase64String(bytes)
            .Replace('+', '-')
            .Replace('/', '_')
            .TrimEnd('=');

        var token = new RefreshToken(
            Guid.CreateVersion7(),
            userId,
            Hash(clear),
            now,
            now.Add(lifetime),
            ipAddress);

        return (clear, token);
    }

    public static byte[] Hash(string clearToken)
        => SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(clearToken));

    public void Revoke(DateTimeOffset at, string reason, Guid? replacedBy = null)
    {
        if (IsRevoked)
        {
            return;
        }

        RevokedAt = at;
        RevokedReason = reason;
        ReplacedByTokenId = replacedBy;
    }
}

/// <summary>A permission the system knows about. Seeded, never user-created.</summary>
public sealed class Permission
{
    private Permission()
    {
    }

    public Permission(string code, string module, string? description = null)
    {
        Id = Guid.CreateVersion7();
        Code = code;
        Module = module;
        Description = description;
    }

    public Guid Id { get; private set; }

    public string Code { get; private set; } = null!;

    public string Module { get; private set; } = null!;

    public string? Description { get; private set; }
}

public sealed class RolePermission
{
    private RolePermission()
    {
    }

    public RolePermission(Guid roleId, Guid permissionId)
    {
        RoleId = roleId;
        PermissionId = permissionId;
    }

    public Guid RoleId { get; private set; }

    public Guid PermissionId { get; private set; }
}

/// <summary>
/// A per-person exception to their role's permissions, in either direction.
/// <para>
/// Revocations win over grants: taking something away must never be defeated by
/// a role that also hands it out.
/// </para>
/// </summary>
public sealed class UserPermissionOverride
{
    private UserPermissionOverride()
    {
    }

    public UserPermissionOverride(Guid userId, Guid permissionId, bool isGranted, string? reason = null)
    {
        UserId = userId;
        PermissionId = permissionId;
        IsGranted = isGranted;
        Reason = reason;
    }

    public Guid UserId { get; private set; }

    public Guid PermissionId { get; private set; }

    public bool IsGranted { get; private set; }

    public string? Reason { get; private set; }
}
