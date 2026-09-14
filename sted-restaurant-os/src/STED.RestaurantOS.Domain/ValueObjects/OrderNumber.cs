using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;

namespace STED.RestaurantOS.Domain.ValueObjects;

/// <summary>
/// The number humans use: shouted across a kitchen, written on a receipt.
/// Format <c>yyMMdd-NNNN</c>, sequential per restaurant per day. Distinct from
/// the surrogate <see cref="Guid"/> primary key, which is never shown.
/// </summary>
public sealed class OrderNumber : ValueObject
{
    public const int MaxLength = 24;

    private OrderNumber(string value) => Value = value;

    public string Value { get; }

    public static OrderNumber Of(string value)
    {
        var trimmed = (value ?? string.Empty).Trim();
        if (trimmed.Length is 0 or > MaxLength)
        {
            throw new DomainValidationException($"Order number must be 1 to {MaxLength} characters.");
        }

        return new OrderNumber(trimmed);
    }

    public static OrderNumber Create(DateTimeOffset localDate, int dailySequence)
    {
        if (dailySequence < 1)
        {
            throw new DomainValidationException("Daily sequence must be greater than zero.");
        }

        return new OrderNumber($"{localDate:yyMMdd}-{dailySequence:D4}");
    }

    public override string ToString() => Value;

    protected override IEnumerable<object?> GetEqualityComponents()
    {
        yield return Value;
    }
}
