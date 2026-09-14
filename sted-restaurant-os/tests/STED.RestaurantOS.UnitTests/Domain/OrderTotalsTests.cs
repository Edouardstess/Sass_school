using FluentAssertions;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.UnitTests.Fixtures;
using Xunit;

namespace STED.RestaurantOS.UnitTests.Domain;

/// <summary>
/// Everything a guest is charged is computed here, from server-side snapshots.
/// These tests are the arithmetic contract of the product.
/// </summary>
public sealed class OrderTotalsTests
{
    private readonly TestClock _clock = TestClock.AtServiceEvening();

    [Fact]
    public void A_single_line_is_price_times_quantity_plus_tax()
    {
        var order = Build.Order(_clock.UtcNow);

        order.AddItem(Build.Pizza(price: 500m, taxRate: 0.10m), quantity: 2);

        order.Subtotal.Amount.Should().Be(1000m);
        order.TaxAmount.Amount.Should().Be(100m);
        order.Total.Amount.Should().Be(1100m);
    }

    [Fact]
    public void Modifier_deltas_apply_per_unit_not_per_line()
    {
        var order = Build.Order(_clock.UtcNow);

        order.AddItem(
            Build.Pizza(price: 500m, taxRate: 0m),
            quantity: 3,
            modifiers:
            [
                new ModifierSelectionSnapshot(Guid.NewGuid(), "Extras", "Extra cheese", Build.Htg(50m)),
            ]);

        // (500 + 50) * 3
        order.Subtotal.Amount.Should().Be(1650m);
    }

    [Fact]
    public void A_negative_modifier_delta_reduces_the_line()
    {
        var order = Build.Order(_clock.UtcNow);

        order.AddItem(
            Build.Pizza(price: 500m, taxRate: 0m),
            quantity: 1,
            modifiers:
            [
                new ModifierSelectionSnapshot(Guid.NewGuid(), "Extras", "No cheese", Build.Htg(-50m)),
            ]);

        order.Subtotal.Amount.Should().Be(450m);
    }

    [Fact]
    public void Service_charge_applies_to_the_subtotal()
    {
        var order = Build.Order(_clock.UtcNow, serviceCharge: 10m);

        order.AddItem(Build.Pizza(price: 1000m, taxRate: 0m), quantity: 1);

        order.ServiceChargeAmount.Amount.Should().Be(100m);
        order.Total.Amount.Should().Be(1100m);
    }

    [Fact]
    public void Tax_inclusive_pricing_extracts_the_tax_instead_of_adding_it()
    {
        var order = Build.Order(_clock.UtcNow, taxIncluded: true);

        order.AddItem(Build.Pizza(price: 1100m, taxRate: 0.10m), quantity: 1);

        order.Subtotal.Amount.Should().Be(1100m);
        order.TaxAmount.Amount.Should().Be(100m);

        // The guest pays the shelf price, not the shelf price plus tax.
        order.Total.Amount.Should().Be(1100m);
    }

    [Fact]
    public void A_discount_reduces_the_total_but_never_the_recorded_subtotal()
    {
        var order = Build.Order(_clock.UtcNow);
        order.AddItem(Build.Pizza(price: 1000m, taxRate: 0m), quantity: 1);

        order.ApplyDiscount(
            Percentage.Of(10m),
            maxAllowed: Percentage.Of(20m),
            reason: "Regular guest",
            appliedBy: Build.Manager,
            at: _clock.UtcNow);

        order.Subtotal.Amount.Should().Be(1000m);
        order.DiscountAmount.Amount.Should().Be(100m);
        order.Total.Amount.Should().Be(900m);
    }

    [Fact]
    public void A_discount_beyond_the_configured_cap_is_refused()
    {
        var order = Build.Order(_clock.UtcNow);
        order.AddItem(Build.Pizza(price: 1000m, taxRate: 0m), quantity: 1);

        var act = () => order.ApplyDiscount(
            Percentage.Of(50m),
            maxAllowed: Percentage.Of(20m),
            reason: "Friend of the owner",
            appliedBy: Build.Manager,
            at: _clock.UtcNow);

        act.Should().Throw<STED.RestaurantOS.Domain.Exceptions.BusinessRuleViolationException>();
    }

    [Fact]
    public void Removing_a_line_recomputes_the_order()
    {
        var order = Build.Order(_clock.UtcNow);
        var pizza = order.AddItem(Build.Pizza(price: 500m, taxRate: 0m), quantity: 1);
        order.AddItem(Build.Mojito(price: 350m, taxRate: 0m), quantity: 1);

        order.RemoveItem(pizza.Id);

        order.Subtotal.Amount.Should().Be(350m);
    }

    [Fact]
    public void An_order_carries_one_station_entry_per_distinct_station()
    {
        var order = Build.Order(_clock.UtcNow);
        order.AddItem(Build.Pizza(), quantity: 1);
        order.AddItem(Build.Pizza(), quantity: 1);
        order.AddItem(Build.Mojito(), quantity: 2);

        // Two kitchen lines and one bar line produce two tickets, not three.
        order.StationIds.Should().HaveCount(2);
    }
}
