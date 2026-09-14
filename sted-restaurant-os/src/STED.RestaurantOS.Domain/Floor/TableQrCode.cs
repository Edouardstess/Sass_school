using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Domain.Floor;

/// <summary>
/// The printed code stuck on a table. It identifies a TABLE, never a waiter and
/// never a session — that separation is what makes the audit trail meaningful.
/// <para>
/// The token itself never reaches the database: only its SHA-256 hash is stored,
/// exactly like a password. A database dump therefore yields no usable QR codes.
/// <see cref="TokenLookupKey"/> is a short non-secret prefix that makes the
/// lookup an index seek instead of a full scan.
/// </para>
/// </summary>
public sealed class TableQrCode : Entity
{
    public const int LookupKeyLength = 12;

    private TableQrCode()
    {
    }

    private TableQrCode(
        Guid id,
        Guid restaurantId,
        Guid tableId,
        byte[] secureTokenHash,
        string tokenLookupKey,
        Guid createdBy,
        DateTimeOffset createdAt,
        DateTimeOffset? expiresAt)
        : base(id)
    {
        RestaurantId = restaurantId;
        TableId = tableId;
        SecureTokenHash = secureTokenHash;
        TokenLookupKey = tokenLookupKey;
        CreatedBy = createdBy;
        CreatedAt = createdAt;
        ExpiresAt = expiresAt;
        IsActive = true;
    }

    public Guid RestaurantId { get; private set; }

    public Guid TableId { get; private set; }

    public byte[] SecureTokenHash { get; private set; } = [];

    public string TokenLookupKey { get; private set; } = null!;

    public bool IsActive { get; private set; }

    public DateTimeOffset? ExpiresAt { get; private set; }

    public DateTimeOffset CreatedAt { get; private set; }

    public DateTimeOffset? RegeneratedAt { get; private set; }

    public Guid CreatedBy { get; private set; }

    public bool IsUsable(DateTimeOffset at) => IsActive && (ExpiresAt is null || ExpiresAt > at);

    internal static TableQrCode Issue(
        Guid restaurantId,
        Guid tableId,
        byte[] secureTokenHash,
        string tokenLookupKey,
        Guid createdBy,
        DateTimeOffset createdAt,
        DateTimeOffset? expiresAt)
    {
        Guard.NotNull(secureTokenHash);
        Guard.NotNullOrWhiteSpace(tokenLookupKey);

        return new TableQrCode(
            Guid.CreateVersion7(),
            restaurantId,
            tableId,
            secureTokenHash,
            tokenLookupKey,
            createdBy,
            createdAt,
            expiresAt);
    }

    internal void Deactivate(DateTimeOffset at)
    {
        IsActive = false;
        RegeneratedAt = at;
    }
}
