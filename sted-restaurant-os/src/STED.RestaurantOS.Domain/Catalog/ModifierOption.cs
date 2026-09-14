using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Domain.Catalog;

/// <summary>One choice inside a modifier group ("no onion", "extra cheese").</summary>
public sealed class ModifierOption : Entity
{
    private ModifierOption()
    {
    }

    private ModifierOption(
        Guid id,
        Guid productModifierId,
        string name,
        Money priceDelta,
        bool isDefault,
        int displayOrder)
        : base(id)
    {
        ProductModifierId = productModifierId;
        Name = name;
        PriceDelta = priceDelta;
        IsDefault = isDefault;
        DisplayOrder = displayOrder;
        IsAvailable = true;
    }

    public Guid ProductModifierId { get; private set; }

    public string Name { get; private set; } = null!;

    /// <summary>May be zero or negative (a discount for removing an ingredient).</summary>
    public Money PriceDelta { get; private set; } = null!;

    public bool IsDefault { get; private set; }

    public bool IsAvailable { get; private set; }

    public int DisplayOrder { get; private set; }

    internal static ModifierOption Create(
        Guid productModifierId,
        string name,
        Money priceDelta,
        bool isDefault = false,
        int displayOrder = 0)
    {
        Guard.NotNullOrWhiteSpace(name);
        return new ModifierOption(
            Guid.CreateVersion7(),
            productModifierId,
            Guard.MaxLength(name.Trim(), 120),
            priceDelta,
            isDefault,
            displayOrder);
    }

    public void Update(string name, Money priceDelta, bool isDefault, int displayOrder)
    {
        Guard.NotNullOrWhiteSpace(name);
        Name = Guard.MaxLength(name.Trim(), 120);
        PriceDelta = priceDelta;
        IsDefault = isDefault;
        DisplayOrder = displayOrder;
    }

    public void SetAvailability(bool isAvailable) => IsAvailable = isAvailable;
}
