using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.ValueObjects;

namespace STED.RestaurantOS.Domain.Ordering;

/// <summary>
/// A modifier choice as it was at the moment of ordering. Names and price deltas
/// are copied, not referenced: renaming "no onion" next month must not rewrite
/// last month's ticket.
/// </summary>
public sealed class OrderItemModifier : Entity
{
    private OrderItemModifier()
    {
    }

    private OrderItemModifier(
        Guid id,
        Guid orderItemId,
        Guid modifierOptionId,
        string modifierNameSnapshot,
        string optionNameSnapshot,
        Money priceDeltaSnapshot)
        : base(id)
    {
        OrderItemId = orderItemId;
        ModifierOptionId = modifierOptionId;
        ModifierNameSnapshot = modifierNameSnapshot;
        OptionNameSnapshot = optionNameSnapshot;
        PriceDeltaSnapshot = priceDeltaSnapshot;
    }

    public Guid OrderItemId { get; private set; }

    public Guid ModifierOptionId { get; private set; }

    public string ModifierNameSnapshot { get; private set; } = null!;

    public string OptionNameSnapshot { get; private set; } = null!;

    public Money PriceDeltaSnapshot { get; private set; } = null!;

    internal static OrderItemModifier Create(
        Guid orderItemId,
        Guid modifierOptionId,
        string modifierName,
        string optionName,
        Money priceDelta)
        => new(Guid.CreateVersion7(), orderItemId, modifierOptionId, modifierName, optionName, priceDelta);
}
