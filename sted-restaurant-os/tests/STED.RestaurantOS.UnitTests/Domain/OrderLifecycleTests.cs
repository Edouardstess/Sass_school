using FluentAssertions;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.UnitTests.Fixtures;
using Xunit;

namespace STED.RestaurantOS.UnitTests.Domain;

public sealed class OrderLifecycleTests
{
    private readonly TestClock _clock = TestClock.AtServiceEvening();

    [Theory]
    [InlineData(OrderStatus.Draft, OrderStatus.Pending, true)]
    [InlineData(OrderStatus.Draft, OrderStatus.Confirmed, true)]
    [InlineData(OrderStatus.Pending, OrderStatus.Confirmed, true)]
    [InlineData(OrderStatus.Confirmed, OrderStatus.InPreparation, true)]
    [InlineData(OrderStatus.InPreparation, OrderStatus.Ready, true)]
    [InlineData(OrderStatus.Ready, OrderStatus.Served, true)]
    [InlineData(OrderStatus.Served, OrderStatus.Closed, true)]
    [InlineData(OrderStatus.Draft, OrderStatus.Served, false)]
    [InlineData(OrderStatus.Ready, OrderStatus.Confirmed, false)]
    [InlineData(OrderStatus.Closed, OrderStatus.Served, false)]
    [InlineData(OrderStatus.Cancelled, OrderStatus.Confirmed, false)]
    public void The_transition_matrix_is_what_the_specification_says(
        OrderStatus from,
        OrderStatus to,
        bool allowed)
        => OrderStatusTransitions.IsAllowed(from, to).Should().Be(allowed);

    [Fact]
    public void Closed_and_cancelled_are_terminal_and_lead_nowhere()
    {
        OrderStatusTransitions.From(OrderStatus.Closed).Should().BeEmpty();
        OrderStatusTransitions.From(OrderStatus.Cancelled).Should().BeEmpty();
    }

    /// <summary>Business test 8: every status change leaves a trace.</summary>
    [Fact]
    public void Every_status_change_is_recorded_with_its_author()
    {
        var order = Build.Order(_clock.UtcNow);
        order.AddItem(Build.Pizza(), quantity: 1);

        order.Confirm(Build.Jean, _clock.UtcNow);
        order.MarkInPreparation(Build.Jean, _clock.AdvanceMinutes(1));
        order.MarkReady(Build.Jean, _clock.AdvanceMinutes(12));
        order.Serve(Build.Marie, _clock.AdvanceMinutes(3));

        order.StatusHistory.Select(h => h.NewStatus).Should().ContainInOrder(
            OrderStatus.Draft,
            OrderStatus.Confirmed,
            OrderStatus.InPreparation,
            OrderStatus.Ready,
            OrderStatus.Served);

        order.StatusHistory.Last().ChangedBy.Should().Be(Build.Marie);
    }

    [Fact]
    public void An_illegal_transition_is_refused_with_a_usable_error_code()
    {
        var order = Build.Order(_clock.UtcNow);
        order.AddItem(Build.Pizza(), quantity: 1);
        order.Confirm(Build.Jean, _clock.UtcNow);

        var act = () => order.Serve(Build.Jean, _clock.UtcNow);

        act.Should().Throw<BusinessRuleViolationException>()
            .Which.Code.Should().Be(STED.RestaurantOS.Shared.Errors.ErrorCodes.InvalidOrderStatusTransition);
    }

    [Fact]
    public void An_empty_order_cannot_be_confirmed()
    {
        var order = Build.Order(_clock.UtcNow);

        var act = () => order.Confirm(Build.Jean, _clock.UtcNow);

        act.Should().Throw<BusinessRuleViolationException>()
            .Which.Code.Should().Be(STED.RestaurantOS.Shared.Errors.ErrorCodes.OrderEmpty);
    }

    [Fact]
    public void A_confirmed_order_can_no_longer_be_edited()
    {
        var order = Build.Order(_clock.UtcNow);
        order.AddItem(Build.Pizza(), quantity: 1);
        order.Confirm(Build.Jean, _clock.UtcNow);

        var act = () => order.AddItem(Build.Mojito(), quantity: 1);

        act.Should().Throw<BusinessRuleViolationException>()
            .Which.Code.Should().Be(STED.RestaurantOS.Shared.Errors.ErrorCodes.OrderImmutable);
    }

    [Fact]
    public void Cancelling_requires_a_reason_and_records_who_did_it()
    {
        var order = Build.Order(_clock.UtcNow);
        order.AddItem(Build.Pizza(), quantity: 1);
        order.Confirm(Build.Jean, _clock.UtcNow);

        order.Cancel(Build.Manager, "Guest left", _clock.AdvanceMinutes(5));

        order.Status.Should().Be(OrderStatus.Cancelled);
        order.CancelledBy.Should().Be(Build.Manager);
        order.CancellationReason.Should().Be("Guest left");
    }

    [Fact]
    public void Serving_records_who_served_and_when()
    {
        var order = Build.Order(_clock.UtcNow);
        order.AddItem(Build.Pizza(), quantity: 1);
        order.Confirm(Build.Jean, _clock.UtcNow);
        order.MarkReady(Build.Jean, _clock.AdvanceMinutes(10));

        var servedAt = _clock.AdvanceMinutes(2);
        order.Serve(Build.Marie, servedAt);

        order.ServedBy.Should().Be(Build.Marie);
        order.ServedAt.Should().Be(servedAt);
    }

    [Fact]
    public void Closing_records_who_took_the_money()
    {
        var order = Build.Order(_clock.UtcNow);
        order.AddItem(Build.Pizza(), quantity: 1);
        order.Confirm(Build.Jean, _clock.UtcNow);
        order.MarkReady(Build.Jean, _clock.AdvanceMinutes(10));
        order.Serve(Build.Jean, _clock.AdvanceMinutes(2));

        order.Close(Build.Manager, _clock.AdvanceMinutes(20));

        order.Status.Should().Be(OrderStatus.Closed);
        order.ClosedBy.Should().Be(Build.Manager);
    }

    /// <summary>
    /// The snapshot rule. A price change must never rewrite an order that has
    /// already been taken — otherwise every historical revenue figure is fiction.
    /// </summary>
    [Fact]
    public void A_later_price_change_does_not_touch_an_order_already_taken()
    {
        var order = Build.Order(_clock.UtcNow);
        var snapshot = Build.Pizza(price: 500m, taxRate: 0m);
        order.AddItem(snapshot, quantity: 2);
        order.Confirm(Build.Jean, _clock.UtcNow);

        var totalAtTheTime = order.Total.Amount;

        // The venue doubles the price of the same product tomorrow.
        var newPrice = new ProductSnapshot(
            snapshot.ProductId, snapshot.Name, Build.Htg(1000m),
            TaxRate.Zero, snapshot.StationId, snapshot.StationCode);

        newPrice.UnitPrice.Amount.Should().Be(1000m);
        order.Total.Amount.Should().Be(totalAtTheTime);
        order.Items.Single().UnitPriceSnapshot.Amount.Should().Be(500m);
    }

    [Fact]
    public void The_product_name_is_kept_as_it_was_sold()
    {
        var order = Build.Order(_clock.UtcNow);
        order.AddItem(Build.Pizza(), quantity: 1);

        order.Items.Single().ProductNameSnapshot.Should().Be("Pizza Margherita");
    }
}
