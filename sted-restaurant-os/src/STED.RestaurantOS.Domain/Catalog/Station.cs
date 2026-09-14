using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Domain.Catalog;

/// <summary>
/// Well-known station codes. Stations are data (a venue can have several bars),
/// but these codes drive the built-in KDS and BDS screens.
/// </summary>
public static class StationCodes
{
    public const string Kitchen = "KITCHEN";
    public const string Bar = "BAR";
    public const string Counter = "COUNTER";
}

/// <summary>
/// Where a product is prepared. This is what splits one guest order into
/// independent tickets: the bar never sees the pizza, the kitchen never sees
/// the mojito, and each advances at its own pace.
/// </summary>
public sealed class Station : AuditableAggregateRoot, ITenantEntity
{
    private Station()
    {
    }

    private Station(Guid id, Guid restaurantId, string code, string name, int displayOrder)
        : base(id)
    {
        RestaurantId = restaurantId;
        Code = code;
        Name = name;
        DisplayOrder = displayOrder;
        IsActive = true;
    }

    public Guid RestaurantId { get; private set; }

    /// <summary>Uppercase, unique per restaurant.</summary>
    public string Code { get; private set; } = null!;

    public string Name { get; private set; } = null!;

    public int DisplayOrder { get; private set; }

    public bool IsActive { get; private set; }

    public static Station Create(Guid restaurantId, string code, string name, int displayOrder = 0)
    {
        Guard.NotEmpty(restaurantId);
        Guard.NotNullOrWhiteSpace(code);
        Guard.NotNullOrWhiteSpace(name);

        return new Station(
            Guid.CreateVersion7(),
            restaurantId,
            Guard.MaxLength(code.Trim().ToUpperInvariant(), 20),
            Guard.MaxLength(name.Trim(), 80),
            displayOrder);
    }

    public void Rename(string name)
    {
        Guard.NotNullOrWhiteSpace(name);
        Name = Guard.MaxLength(name.Trim(), 80);
    }

    public void Reorder(int displayOrder) => DisplayOrder = displayOrder;

    public void Activate() => IsActive = true;

    public void Deactivate() => IsActive = false;
}
