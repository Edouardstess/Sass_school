using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Domain.Staff;

/// <summary>
/// The operational identity of an employee inside one restaurant, kept separate
/// from the authentication account (<c>ApplicationUser</c>, which lives in the
/// identity infrastructure). Orders, assignments and payments point here, so the
/// business model never depends on the identity provider's schema.
/// </summary>
public sealed class StaffProfile : AuditableAggregateRoot, ITenantEntity
{
    private StaffProfile()
    {
    }

    private StaffProfile(
        Guid id,
        Guid userId,
        Guid restaurantId,
        EmployeeCode employeeCode,
        string displayName,
        StaffRole primaryRole,
        DateTimeOffset hiredAt)
        : base(id)
    {
        UserId = userId;
        RestaurantId = restaurantId;
        EmployeeCode = employeeCode;
        DisplayName = displayName;
        PrimaryRole = primaryRole;
        HiredAt = hiredAt;
        IsActive = true;
    }

    public Guid UserId { get; private set; }

    public Guid RestaurantId { get; private set; }

    public EmployeeCode EmployeeCode { get; private set; } = null!;

    public string DisplayName { get; private set; } = null!;

    public string? Phone { get; private set; }

    public StaffRole PrimaryRole { get; private set; }

    public bool IsActive { get; private set; }

    public DateTimeOffset HiredAt { get; private set; }

    public bool IsWaiter => PrimaryRole == StaffRole.Waiter;

    /// <summary>Can this profile be given a table right now?</summary>
    public bool CanTakeTables => IsActive && PrimaryRole is StaffRole.Waiter or StaffRole.Manager or StaffRole.RestaurantAdmin;

    public static StaffProfile Create(
        Guid userId,
        Guid restaurantId,
        string employeeCode,
        string displayName,
        StaffRole primaryRole,
        DateTimeOffset hiredAt,
        string? phone = null)
    {
        Guard.NotEmpty(userId);
        Guard.NotEmpty(restaurantId);
        Guard.NotNullOrWhiteSpace(displayName);

        var profile = new StaffProfile(
            Guid.CreateVersion7(),
            userId,
            restaurantId,
            ValueObjects.EmployeeCode.Of(employeeCode),
            Guard.MaxLength(displayName.Trim(), 120),
            primaryRole,
            hiredAt);

        profile.Phone = phone?.Trim();
        return profile;
    }

    public void UpdateProfile(string displayName, string? phone, StaffRole primaryRole)
    {
        Guard.NotNullOrWhiteSpace(displayName);
        DisplayName = Guard.MaxLength(displayName.Trim(), 120);
        Phone = phone?.Trim();
        PrimaryRole = primaryRole;
    }

    public void ChangeEmployeeCode(string employeeCode)
        => EmployeeCode = ValueObjects.EmployeeCode.Of(employeeCode);

    public void Activate() => IsActive = true;

    /// <summary>
    /// Deactivating never deletes: the staff member stays joined to every past
    /// order, assignment and payment they touched.
    /// </summary>
    public void Deactivate() => IsActive = false;
}
