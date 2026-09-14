using FluentAssertions;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.UnitTests.Fixtures;
using Xunit;

namespace STED.RestaurantOS.UnitTests.Domain;

/// <summary>
/// Business tests 2 and 3 of the specification, and the reason
/// <c>Order.WaiterId</c> is frozen while the assignment chain stays live.
/// <para>
/// The scenario is the one from the brief: Jean opens table 12 at 19:00, an
/// order is placed at 19:15, Jean hands the table to Marie at 19:45, another
/// order is placed at 20:00.
/// </para>
/// </summary>
public sealed class WaiterAttributionTests
{
    private readonly TestClock _clock = TestClock.AtServiceEvening();

    [Fact]
    public void A_transfer_does_not_rewrite_the_waiter_of_orders_already_placed()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);

        // 19:15 — first order, while Jean is serving.
        var first = Build.Order(_clock.AdvanceMinutes(15), session.Id);
        first.AddItem(Build.Pizza(), quantity: 1);
        first.Confirm(session.CurrentWaiterId, _clock.UtcNow);

        first.WaiterId.Should().Be(Build.Jean);

        // 19:45 — hand-over.
        session.TransferTo(Build.Marie, Build.Jean, actingAsManager: false, _clock.AdvanceMinutes(30));

        // The past does not move.
        first.WaiterId.Should().Be(Build.Jean);
    }

    [Fact]
    public void An_order_placed_after_the_transfer_belongs_to_the_new_waiter()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);
        session.TransferTo(Build.Marie, Build.Jean, actingAsManager: false, _clock.AdvanceMinutes(45));

        // 20:00 — second order.
        var second = Build.Order(_clock.AdvanceMinutes(15), session.Id);
        second.AddItem(Build.Mojito(), quantity: 2);
        second.Confirm(session.CurrentWaiterId, _clock.UtcNow);

        second.WaiterId.Should().Be(Build.Marie);
    }

    [Fact]
    public void The_waiter_is_set_once_and_never_moves_afterwards()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);

        var order = Build.Order(_clock.UtcNow, session.Id);
        order.AddItem(Build.Pizza(), quantity: 1);
        order.Confirm(Build.Jean, _clock.UtcNow);

        // Serving, and anything else that happens later, leaves the attribution alone.
        order.MarkInPreparation(Build.Jean, _clock.AdvanceMinutes(2));
        order.MarkReady(Build.Jean, _clock.AdvanceMinutes(10));
        order.Serve(Build.Marie, _clock.AdvanceMinutes(2));

        order.WaiterId.Should().Be(Build.Jean);

        // Who physically carried the plate is a separate, equally recorded fact.
        order.ServedBy.Should().Be(Build.Marie);
    }

    [Fact]
    public void An_order_placed_at_a_table_nobody_has_taken_is_valid_and_has_no_waiter()
    {
        var session = Build.Session(_clock.UtcNow);

        var order = Build.Order(_clock.UtcNow, session.Id);
        order.AddItem(Build.Pizza(), quantity: 1);
        order.Confirm(session.CurrentWaiterId, _clock.UtcNow);

        // The kitchen still starts cooking. That is the entire point of the product.
        order.Status.Should().Be(OrderStatus.Confirmed);
        order.WaiterId.Should().BeNull();
    }
}
