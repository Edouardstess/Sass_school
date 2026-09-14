using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Domain.Floor;

/// <summary>A named area of the venue: terrace, main room, VIP, bar, lounge.</summary>
public sealed class TableZone : AuditableAggregateRoot, ITenantEntity
{
    private TableZone()
    {
    }

    private TableZone(Guid id, Guid restaurantId, string name, int displayOrder)
        : base(id)
    {
        RestaurantId = restaurantId;
        Name = name;
        DisplayOrder = displayOrder;
        IsActive = true;
    }

    public Guid RestaurantId { get; private set; }

    public string Name { get; private set; } = null!;

    public int DisplayOrder { get; private set; }

    public bool IsActive { get; private set; }

    public static TableZone Create(Guid restaurantId, string name, int displayOrder = 0)
    {
        Guard.NotEmpty(restaurantId);
        Guard.NotNullOrWhiteSpace(name);
        return new TableZone(Guid.CreateVersion7(), restaurantId, Guard.MaxLength(name.Trim(), 80), displayOrder);
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
