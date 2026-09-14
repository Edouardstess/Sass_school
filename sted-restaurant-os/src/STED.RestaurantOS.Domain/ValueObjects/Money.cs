using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Domain.ValueObjects;

/// <summary>
/// A monetary amount in a specific currency.
/// <para>
/// Always <see cref="decimal"/>, never double: HTG amounts run to six figures and
/// binary rounding error on repeated additions turns into a real cash drawer
/// discrepancy. Rounding is applied once, at construction, to 2 decimals,
/// away from zero — never accumulated across intermediate totals.
/// </para>
/// </summary>
public sealed class Money : ValueObject
{
    public const int Scale = 2;

    private Money(decimal amount, string currency)
    {
        Amount = decimal.Round(amount, Scale, MidpointRounding.AwayFromZero);
        Currency = currency;
    }

    public decimal Amount { get; }

    public string Currency { get; }

    public bool IsZero => Amount == 0m;

    public bool IsNegative => Amount < 0m;

    public static Money Of(decimal amount, string currency)
    {
        if (string.IsNullOrWhiteSpace(currency) || currency.Length != 3)
        {
            throw new DomainValidationException("Currency must be a 3-letter ISO 4217 code.");
        }

        return new Money(amount, currency.ToUpperInvariant());
    }

    public static Money Zero(string currency) => Of(0m, currency);

    public Money Add(Money other)
    {
        EnsureSameCurrency(other);
        return new Money(Amount + other.Amount, Currency);
    }

    public Money Subtract(Money other)
    {
        EnsureSameCurrency(other);
        return new Money(Amount - other.Amount, Currency);
    }

    public Money Multiply(int quantity) => new(Amount * quantity, Currency);

    public Money Multiply(decimal factor) => new(Amount * factor, Currency);

    /// <summary>Applies a tax rate and returns the tax amount (not the gross).</summary>
    public Money ApplyRate(TaxRate rate) => new(Amount * rate.Value, Currency);

    public static Money Sum(IEnumerable<Money> amounts, string currency)
    {
        var total = Zero(currency);
        foreach (var amount in amounts)
        {
            total = total.Add(amount);
        }

        return total;
    }

    public static Money operator +(Money left, Money right) => left.Add(right);

    public static Money operator -(Money left, Money right) => left.Subtract(right);

    public static Money operator *(Money money, int quantity) => money.Multiply(quantity);

    public static bool operator >(Money left, Money right)
    {
        left.EnsureSameCurrency(right);
        return left.Amount > right.Amount;
    }

    public static bool operator <(Money left, Money right)
    {
        left.EnsureSameCurrency(right);
        return left.Amount < right.Amount;
    }

    public static bool operator >=(Money left, Money right)
    {
        left.EnsureSameCurrency(right);
        return left.Amount >= right.Amount;
    }

    public static bool operator <=(Money left, Money right)
    {
        left.EnsureSameCurrency(right);
        return left.Amount <= right.Amount;
    }

    public override string ToString() => $"{Amount.ToString("0.00")} {Currency}";

    protected override IEnumerable<object?> GetEqualityComponents()
    {
        yield return Amount;
        yield return Currency;
    }

    private void EnsureSameCurrency(Money other)
    {
        if (!string.Equals(Currency, other.Currency, StringComparison.Ordinal))
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.CurrencyMismatch,
                $"Cannot combine amounts in {Currency} and {other.Currency}.");
        }
    }
}
