using STED.RestaurantOS.Domain.ValueObjects;

namespace STED.RestaurantOS.Domain.Ordering;

/// <summary>
/// Catalogue data copied into an order line. Built by the application layer from
/// the <c>Product</c> row read inside the confirmation transaction — never from
/// anything the client sent.
/// </summary>
public sealed record ProductSnapshot(
    Guid ProductId,
    string Name,
    Money UnitPrice,
    TaxRate TaxRate,
    Guid StationId,
    string StationCode);

/// <summary>One chosen modifier option, copied at ordering time.</summary>
public sealed record ModifierSelectionSnapshot(
    Guid ModifierOptionId,
    string ModifierName,
    string OptionName,
    Money PriceDelta);
