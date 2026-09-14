using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;

namespace STED.RestaurantOS.Domain.ValueObjects;

/// <summary>A percentage in the 0..100 range, used for discounts and service charges.</summary>
public sealed class Percentage : ValueObject
{
    private Percentage(decimal value) => Value = decimal.Round(value, 2, MidpointRounding.AwayFromZero);

    public decimal Value { get; }

    public static Percentage Zero { get; } = new(0m);

    public static Percentage Of(decimal value)
    {
        if (value < 0m || value > 100m)
        {
            throw new DomainValidationException("Percentage must be between 0 and 100.");
        }

        return new Percentage(value);
    }

    public Money AppliedTo(Money amount) => amount.Multiply(Value / 100m);

    public override string ToString() => $"{Value.ToString("0.##")}%";

    protected override IEnumerable<object?> GetEqualityComponents()
    {
        yield return Value;
    }
}
