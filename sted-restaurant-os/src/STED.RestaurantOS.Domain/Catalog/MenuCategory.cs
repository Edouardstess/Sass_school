using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Domain.Catalog;

public sealed class MenuCategory : AuditableAggregateRoot, ITenantEntity
{
    private MenuCategory()
    {
    }

    private MenuCategory(Guid id, Guid restaurantId, string name, int displayOrder)
        : base(id)
    {
        RestaurantId = restaurantId;
        Name = name;
        DisplayOrder = displayOrder;
        IsActive = true;
    }

    public Guid RestaurantId { get; private set; }

    public string Name { get; private set; } = null!;

    public string? Description { get; private set; }

    public string? ImageUrl { get; private set; }

    public int DisplayOrder { get; private set; }

    public bool IsActive { get; private set; }

    public static MenuCategory Create(Guid restaurantId, string name, int displayOrder = 0)
    {
        Guard.NotEmpty(restaurantId);
        Guard.NotNullOrWhiteSpace(name);
        return new MenuCategory(
            Guid.CreateVersion7(), restaurantId, Guard.MaxLength(name.Trim(), 120), displayOrder);
    }

    public void Update(string name, string? description, string? imageUrl, int displayOrder)
    {
        Guard.NotNullOrWhiteSpace(name);
        Name = Guard.MaxLength(name.Trim(), 120);
        Description = description?.Trim();
        ImageUrl = imageUrl?.Trim();
        DisplayOrder = displayOrder;
    }

    public void Activate() => IsActive = true;

    public void Deactivate() => IsActive = false;
}
