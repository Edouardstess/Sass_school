using FluentAssertions;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Domain.ValueObjects;
using Xunit;

namespace STED.RestaurantOS.UnitTests.Domain;

public sealed class MoneyTests
{
    [Fact]
    public void Rounds_to_two_decimals_away_from_zero()
    {
        Money.Of(12.345m, "HTG").Amount.Should().Be(12.35m);
        Money.Of(12.344m, "HTG").Amount.Should().Be(12.34m);
    }

    [Fact]
    public void Refuses_to_mix_currencies()
    {
        var gourdes = Money.Of(100m, "HTG");
        var dollars = Money.Of(100m, "USD");

        var act = () => gourdes.Add(dollars);

        act.Should().Throw<BusinessRuleViolationException>();
    }

    [Fact]
    public void Rejects_a_currency_that_is_not_an_iso_code()
    {
        var act = () => Money.Of(10m, "GOURDE");

        act.Should().Throw<DomainValidationException>();
    }

    [Fact]
    public void Normalises_the_currency_code_to_upper_case()
        => Money.Of(10m, "htg").Currency.Should().Be("HTG");

    [Fact]
    public void Equality_is_by_value()
        => Money.Of(10m, "HTG").Should().Be(Money.Of(10m, "HTG"));

    /// <summary>
    /// The reason this system uses decimal rather than double: a hundred small
    /// additions must land exactly, or the cash drawer disagrees with the report.
    /// </summary>
    [Fact]
    public void Repeated_addition_does_not_drift()
    {
        var total = Money.Zero("HTG");

        for (var i = 0; i < 100; i++)
        {
            total = total.Add(Money.Of(0.1m, "HTG"));
        }

        total.Amount.Should().Be(10.00m);
    }

    [Fact]
    public void Applies_a_tax_rate_to_produce_the_tax_amount_only()
        => Money.Of(1000m, "HTG").ApplyRate(TaxRate.Of(0.10m)).Amount.Should().Be(100m);

    [Fact]
    public void Sums_a_sequence_in_a_single_currency()
    {
        var amounts = new[] { Money.Of(10m, "HTG"), Money.Of(20m, "HTG"), Money.Of(0.5m, "HTG") };

        Money.Sum(amounts, "HTG").Amount.Should().Be(30.5m);
    }

    [Fact]
    public void Compares_amounts_within_the_same_currency()
        => (Money.Of(10m, "HTG") > Money.Of(9.99m, "HTG")).Should().BeTrue();
}
