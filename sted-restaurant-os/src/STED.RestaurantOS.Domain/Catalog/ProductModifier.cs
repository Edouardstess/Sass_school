using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Domain.Catalog;

/// <summary>
/// A group of choices attached to a product ("Cooking", "Extras"), with its own
/// selection rules. Part of the <see cref="Product"/> aggregate.
/// </summary>
public sealed class ProductModifier : Entity
{
    private readonly List<ModifierOption> _options = [];

    private ProductModifier()
    {
    }

    private ProductModifier(
        Guid id,
        Guid productId,
        string name,
        bool isRequired,
        int minSelections,
        int maxSelections,
        int displayOrder)
        : base(id)
    {
        ProductId = productId;
        Name = name;
        IsRequired = isRequired;
        MinSelections = minSelections;
        MaxSelections = maxSelections;
        DisplayOrder = displayOrder;
    }

    public Guid ProductId { get; private set; }

    public string Name { get; private set; } = null!;

    public bool IsRequired { get; private set; }

    public int MinSelections { get; private set; }

    public int MaxSelections { get; private set; }

    public int DisplayOrder { get; private set; }

    public IReadOnlyCollection<ModifierOption> Options => _options.AsReadOnly();

    internal static ProductModifier Create(
        Guid productId,
        string name,
        bool isRequired,
        int minSelections,
        int maxSelections,
        int displayOrder = 0)
    {
        Guard.NotNullOrWhiteSpace(name);

        if (minSelections < 0 || maxSelections < 1 || minSelections > maxSelections)
        {
            throw new DomainValidationException(
                "Modifier selection bounds are invalid: 0 <= min <= max and max >= 1.");
        }

        return new ProductModifier(
            Guid.CreateVersion7(),
            productId,
            Guard.MaxLength(name.Trim(), 120),
            isRequired,
            minSelections,
            maxSelections,
            displayOrder);
    }

    public ModifierOption AddOption(string name, Money priceDelta, bool isDefault = false, int displayOrder = 0)
    {
        var option = ModifierOption.Create(Id, name, priceDelta, isDefault, displayOrder);
        _options.Add(option);
        return option;
    }

    public void RemoveOption(Guid optionId) => _options.RemoveAll(o => o.Id == optionId);

    /// <summary>
    /// Validates a guest's picks against this group's rules. Called server-side
    /// during order confirmation; the client's own validation is a courtesy, not
    /// a control.
    /// </summary>
    public void EnsureSelectionIsValid(IReadOnlyCollection<Guid> selectedOptionIds)
    {
        var count = selectedOptionIds.Count;

        if (IsRequired && count < Math.Max(1, MinSelections))
        {
            throw new BusinessRuleViolationException(
                Shared.Errors.ErrorCodes.ModifierSelectionInvalid,
                $"'{Name}' requires at least {Math.Max(1, MinSelections)} selection(s).");
        }

        if (count < MinSelections || count > MaxSelections)
        {
            throw new BusinessRuleViolationException(
                Shared.Errors.ErrorCodes.ModifierSelectionInvalid,
                $"'{Name}' accepts between {MinSelections} and {MaxSelections} selection(s).");
        }

        foreach (var optionId in selectedOptionIds)
        {
            var option = _options.SingleOrDefault(o => o.Id == optionId)
                ?? throw new BusinessRuleViolationException(
                    Shared.Errors.ErrorCodes.ModifierSelectionInvalid,
                    $"Unknown option selected for '{Name}'.");

            if (!option.IsAvailable)
            {
                throw new BusinessRuleViolationException(
                    Shared.Errors.ErrorCodes.ProductUnavailable,
                    $"'{option.Name}' is currently unavailable.");
            }
        }
    }
}
