using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Shared;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Domain.Catalog;

/// <summary>
/// Something a guest can order. Owns its modifier groups, since "min &lt;= max"
/// and "a required group has options" only make sense as a whole.
/// <para>
/// The price here is the price <em>today</em>. Orders never read it again after
/// confirmation: they snapshot it. Changing this price must never rewrite
/// yesterday's revenue.
/// </para>
/// </summary>
public sealed class Product : AuditableAggregateRoot, ITenantEntity, IHasRowVersion
{
    private readonly List<ProductModifier> _modifiers = [];

    private Product()
    {
    }

    private Product(
        Guid id,
        Guid restaurantId,
        Guid categoryId,
        Guid stationId,
        string name,
        Money price,
        TaxRate taxRate,
        int preparationMinutes,
        int displayOrder)
        : base(id)
    {
        RestaurantId = restaurantId;
        CategoryId = categoryId;
        StationId = stationId;
        Name = name;
        Price = price;
        TaxRate = taxRate;
        PreparationMinutes = preparationMinutes;
        DisplayOrder = displayOrder;
        IsAvailable = true;
        IsActive = true;
    }

    public Guid RestaurantId { get; private set; }

    public Guid CategoryId { get; private set; }

    /// <summary>Mandatory: a product nobody prepares cannot be ordered.</summary>
    public Guid StationId { get; private set; }

    public string Name { get; private set; } = null!;

    public string? Description { get; private set; }

    public string? ImageUrl { get; private set; }

    public Money Price { get; private set; } = null!;

    public TaxRate TaxRate { get; private set; } = TaxRate.Zero;

    /// <summary>Feeds the KDS late-ticket threshold.</summary>
    public int PreparationMinutes { get; private set; }

    /// <summary>Today's stock reality: flipped in one tap when the kitchen runs out.</summary>
    public bool IsAvailable { get; private set; }

    /// <summary>Permanently retired. Never deleted, or history loses its joins.</summary>
    public bool IsActive { get; private set; }

    public int DisplayOrder { get; private set; }

    public byte[]? RowVersion { get; private set; }

    public IReadOnlyCollection<ProductModifier> Modifiers => _modifiers.AsReadOnly();

    public bool IsOrderable => IsActive && IsAvailable;

    public static Product Create(
        Guid restaurantId,
        Guid categoryId,
        Guid stationId,
        string name,
        Money price,
        TaxRate taxRate,
        int preparationMinutes = 10,
        int displayOrder = 0)
    {
        Guard.NotEmpty(restaurantId);
        Guard.NotEmpty(categoryId);
        Guard.NotEmpty(stationId);
        Guard.NotNullOrWhiteSpace(name);
        Guard.NotNull(price);

        if (price.IsNegative)
        {
            throw new DomainValidationException("Product price cannot be negative.");
        }

        return new Product(
            Guid.CreateVersion7(),
            restaurantId,
            categoryId,
            stationId,
            Guard.MaxLength(name.Trim(), 160),
            price,
            taxRate,
            preparationMinutes < 0 ? 0 : preparationMinutes,
            displayOrder);
    }

    public void UpdateDetails(
        string name,
        string? description,
        string? imageUrl,
        Guid categoryId,
        Guid stationId,
        int preparationMinutes,
        int displayOrder)
    {
        Guard.NotNullOrWhiteSpace(name);
        Guard.NotEmpty(categoryId);
        Guard.NotEmpty(stationId);

        Name = Guard.MaxLength(name.Trim(), 160);
        Description = description?.Trim();
        ImageUrl = imageUrl?.Trim();
        CategoryId = categoryId;
        StationId = stationId;
        PreparationMinutes = preparationMinutes < 0 ? 0 : preparationMinutes;
        DisplayOrder = displayOrder;
    }

    public void ChangePricing(Money price, TaxRate taxRate)
    {
        Guard.NotNull(price);
        if (price.IsNegative)
        {
            throw new DomainValidationException("Product price cannot be negative.");
        }

        Price = price;
        TaxRate = taxRate;
    }

    public ProductModifier AddModifier(
        string name,
        bool isRequired,
        int minSelections,
        int maxSelections,
        int displayOrder = 0)
    {
        var modifier = ProductModifier.Create(Id, name, isRequired, minSelections, maxSelections, displayOrder);
        _modifiers.Add(modifier);
        return modifier;
    }

    public void RemoveModifier(Guid modifierId) => _modifiers.RemoveAll(m => m.Id == modifierId);

    public void SetAvailability(bool isAvailable) => IsAvailable = isAvailable;

    public void Activate() => IsActive = true;

    public void Deactivate()
    {
        IsActive = false;
        IsAvailable = false;
    }

    /// <summary>Guard used by the order engine before snapshotting this product.</summary>
    public void EnsureOrderable()
    {
        if (!IsOrderable)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.ProductUnavailable,
                $"'{Name}' is not available right now.");
        }
    }

    /// <summary>
    /// Validates a whole set of modifier selections for one order line, including
    /// required groups the guest simply did not send.
    /// </summary>
    public void EnsureModifierSelectionIsValid(IReadOnlyDictionary<Guid, IReadOnlyCollection<Guid>> selectionsByModifier)
    {
        foreach (var modifier in _modifiers)
        {
            var selected = selectionsByModifier.TryGetValue(modifier.Id, out var ids)
                ? ids
                : Array.Empty<Guid>();

            modifier.EnsureSelectionIsValid(selected);
        }

        foreach (var modifierId in selectionsByModifier.Keys)
        {
            if (_modifiers.All(m => m.Id != modifierId))
            {
                throw new BusinessRuleViolationException(
                    ErrorCodes.ModifierSelectionInvalid,
                    "A modifier that does not belong to this product was submitted.");
            }
        }
    }
}
