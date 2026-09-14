using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;

namespace STED.RestaurantOS.Domain.ValueObjects;

/// <summary>A tax rate expressed as a fraction: 0.10 means 10%.</summary>
public sealed class TaxRate : ValueObject
{
    public const int Scale = 4;

    private TaxRate(decimal value) => Value = decimal.Round(value, Scale, MidpointRounding.AwayFromZero);

    public decimal Value { get; }

    public static TaxRate Zero { get; } = new(0m);

    public static TaxRate Of(decimal value)
    {
        if (value < 0m || value > 1m)
        {
            throw new DomainValidationException("Tax rate must be between 0 and 1 (0.10 = 10%).");
        }

        return new TaxRate(value);
    }

    public static TaxRate FromPercentage(decimal percentage) => Of(percentage / 100m);

    public decimal AsPercentage => Value * 100m;

    public override string ToString() => $"{AsPercentage.ToString("0.##")}%";

    protected override IEnumerable<object?> GetEqualityComponents()
    {
        yield return Value;
    }
}
