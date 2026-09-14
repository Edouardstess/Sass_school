using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Domain.Floor.Events;
using STED.RestaurantOS.Shared;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Domain.Floor;

/// <summary>
/// The physical table. It lives for years; a service episode lives for an hour.
/// That is why orders hang off <see cref="TableSession"/> and not off this
/// entity — otherwise "how many covers did table 12 do last night?" would be
/// unanswerable.
/// <para>
/// The table owns its QR codes because "at most one active QR per table" is an
/// invariant that must hold immediately.
/// </para>
/// </summary>
public sealed class RestaurantTable : AuditableAggregateRoot, ITenantEntity, IHasRowVersion
{
    private readonly List<TableQrCode> _qrCodes = [];

    private RestaurantTable()
    {
    }

    private RestaurantTable(Guid id, Guid restaurantId, string number, int capacity, Guid? zoneId)
        : base(id)
    {
        RestaurantId = restaurantId;
        Number = number;
        Capacity = capacity;
        ZoneId = zoneId;
        Status = TableStatus.Available;
        IsActive = true;
    }

    public Guid RestaurantId { get; private set; }

    public Guid? ZoneId { get; private set; }

    /// <summary>Displayed everywhere; unique within the restaurant.</summary>
    public string Number { get; private set; } = null!;

    public string? Name { get; private set; }

    public int Capacity { get; private set; }

    public TableStatus Status { get; private set; }

    public bool IsActive { get; private set; }

    public byte[]? RowVersion { get; private set; }

    public IReadOnlyCollection<TableQrCode> QrCodes => _qrCodes.AsReadOnly();

    public TableQrCode? ActiveQrCode => _qrCodes.SingleOrDefault(q => q.IsActive);

    public bool CanOpenSession => IsActive && Status is TableStatus.Available or TableStatus.Reserved or TableStatus.Occupied;

    public static RestaurantTable Create(
        Guid restaurantId,
        string number,
        int capacity,
        Guid? zoneId = null,
        string? name = null)
    {
        Guard.NotEmpty(restaurantId);
        Guard.NotNullOrWhiteSpace(number);
        Guard.InRange(capacity, 1, 50);

        var table = new RestaurantTable(
            Guid.CreateVersion7(),
            restaurantId,
            Guard.MaxLength(number.Trim(), 20),
            capacity,
            zoneId);

        table.Name = name?.Trim();
        return table;
    }

    public void UpdateDetails(string number, int capacity, Guid? zoneId, string? name)
    {
        Guard.NotNullOrWhiteSpace(number);
        Number = Guard.MaxLength(number.Trim(), 20);
        Capacity = Guard.InRange(capacity, 1, 50);
        ZoneId = zoneId;
        Name = name?.Trim();
    }

    /// <summary>
    /// Issues a fresh QR code and retires the previous one in the same call, so
    /// the "one active code" invariant can never be observed broken.
    /// </summary>
    public TableQrCode IssueQrCode(
        byte[] secureTokenHash,
        string tokenLookupKey,
        Guid issuedBy,
        DateTimeOffset at,
        DateTimeOffset? expiresAt = null)
    {
        foreach (var existing in _qrCodes.Where(q => q.IsActive))
        {
            existing.Deactivate(at);
        }

        var code = TableQrCode.Issue(RestaurantId, Id, secureTokenHash, tokenLookupKey, issuedBy, at, expiresAt);
        _qrCodes.Add(code);
        Raise(new TableQrCodeRegeneratedDomainEvent(RestaurantId, Id, code.Id, issuedBy, at));
        return code;
    }

    public void DeactivateQrCodes(DateTimeOffset at)
    {
        foreach (var existing in _qrCodes.Where(q => q.IsActive))
        {
            existing.Deactivate(at);
        }
    }

    public void MarkOccupied() => ChangeStatus(TableStatus.Occupied);

    public void MarkAvailable() => ChangeStatus(TableStatus.Available);

    public void MarkCleaning() => ChangeStatus(TableStatus.Cleaning);

    public void MarkReserved() => ChangeStatus(TableStatus.Reserved);

    public void MarkOutOfService() => ChangeStatus(TableStatus.OutOfService);

    public void Activate() => IsActive = true;

    public void Deactivate() => IsActive = false;

    private void ChangeStatus(TableStatus status)
    {
        if (!IsActive && status != TableStatus.OutOfService)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.TableNotFound,
                "An inactive table cannot change status.");
        }

        Status = status;
    }
}
