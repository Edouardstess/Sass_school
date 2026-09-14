using FluentAssertions;
using STED.RestaurantOS.Domain.Billing;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Domain.Preparation;
using STED.RestaurantOS.UnitTests.Fixtures;
using Xunit;

namespace STED.RestaurantOS.UnitTests.Domain;

public sealed class PreparationTicketTests
{
    private readonly TestClock _clock = TestClock.AtServiceEvening();

    private PreparationTicket NewTicket() => PreparationTicket.Create(
        Build.RestaurantId, Guid.NewGuid(), Build.KitchenStation, "260314-0001", _clock.UtcNow);

    [Fact]
    public void A_ticket_moves_forward_through_the_station_workflow()
    {
        var ticket = NewTicket();

        ticket.Accept(Build.Jean, _clock.AdvanceMinutes(1));
        ticket.Start(Build.Jean, _clock.AdvanceMinutes(1));
        ticket.MarkReady(Build.Jean, _clock.AdvanceMinutes(9));

        ticket.Status.Should().Be(PreparationTicketStatus.Ready);
        ticket.AcceptedBy.Should().Be(Build.Jean);
        ticket.CompletedBy.Should().Be(Build.Jean);
        ticket.ReadyAt.Should().NotBeNull();
    }

    [Fact]
    public void A_ticket_cannot_go_backwards()
    {
        var ticket = NewTicket();
        ticket.MarkReady(Build.Jean, _clock.AdvanceMinutes(5));

        var act = () => ticket.Accept(Build.Jean, _clock.UtcNow);

        act.Should().Throw<BusinessRuleViolationException>();
    }

    [Fact]
    public void A_picked_up_ticket_is_finished_for_good()
    {
        var ticket = NewTicket();
        ticket.MarkReady(Build.Jean, _clock.AdvanceMinutes(5));
        ticket.MarkPickedUp(Build.Marie, _clock.AdvanceMinutes(1));

        var act = () => ticket.Cancel(Build.Manager, _clock.UtcNow);

        act.Should().Throw<BusinessRuleViolationException>();
    }

    /// <summary>The colour coding a cook reads from across the room.</summary>
    [Fact]
    public void A_ticket_is_late_once_it_passes_the_configured_threshold()
    {
        var ticket = NewTicket();
        ticket.Accept(Build.Jean, _clock.UtcNow);

        var later = _clock.AdvanceMinutes(20);

        ticket.IsLateAt(later, lateThresholdMinutes: 15).Should().BeTrue();
        ticket.IsLateAt(later, lateThresholdMinutes: 30).Should().BeFalse();
    }

    [Fact]
    public void A_ticket_that_is_already_ready_is_never_late()
    {
        var ticket = NewTicket();
        ticket.MarkReady(Build.Jean, _clock.AdvanceMinutes(5));

        ticket.IsLateAt(_clock.AdvanceMinutes(60), lateThresholdMinutes: 15).Should().BeFalse();
    }

    [Fact]
    public void Elapsed_time_stops_at_the_moment_the_ticket_was_ready()
    {
        var ticket = NewTicket();
        ticket.MarkReady(Build.Jean, _clock.AdvanceMinutes(7));

        ticket.ElapsedAt(_clock.AdvanceMinutes(60)).Should().Be(TimeSpan.FromMinutes(7));
    }
}

public sealed class PaymentTests
{
    private readonly TestClock _clock = TestClock.AtServiceEvening();

    private static Payment NewPayment(decimal amount, string key = "key-1") => Payment.Create(
        Build.RestaurantId,
        Guid.NewGuid(),
        Guid.NewGuid(),
        Build.Htg(amount),
        PaymentMethod.Cash,
        Build.Manager,
        key);

    [Fact]
    public void A_payment_starts_pending_and_completes_with_a_timestamp()
    {
        var payment = NewPayment(1100m);

        payment.Status.Should().Be(PaymentStatus.Pending);

        payment.MarkCompleted(_clock.UtcNow);

        payment.Status.Should().Be(PaymentStatus.Completed);
        payment.PaidAt.Should().Be(_clock.UtcNow);
        payment.CountsTowardsSettlement.Should().BeTrue();
    }

    [Fact]
    public void A_zero_or_negative_payment_is_refused()
    {
        var act = () => NewPayment(0m);

        act.Should().Throw<DomainValidationException>();
    }

    /// <summary>RG-070: a venue can never take more than it is owed.</summary>
    [Fact]
    public void A_payment_that_would_exceed_the_order_total_is_refused()
    {
        var act = () => Payment.EnsureDoesNotExceedTotal(
            alreadyPaid: Build.Htg(900m),
            candidate: Build.Htg(300m),
            orderTotal: Build.Htg(1100m));

        act.Should().Throw<BusinessRuleViolationException>()
            .Which.Code.Should().Be(STED.RestaurantOS.Shared.Errors.ErrorCodes.PaymentExceedsTotal);
    }

    [Fact]
    public void Splitting_a_bill_across_several_payments_is_allowed_up_to_the_total()
    {
        var act = () => Payment.EnsureDoesNotExceedTotal(
            alreadyPaid: Build.Htg(600m),
            candidate: Build.Htg(500m),
            orderTotal: Build.Htg(1100m));

        act.Should().NotThrow();
    }

    [Fact]
    public void Only_a_completed_payment_can_be_refunded()
    {
        var payment = NewPayment(500m);

        var act = () => payment.Refund(_clock.UtcNow);

        act.Should().Throw<BusinessRuleViolationException>();
    }

    [Fact]
    public void A_refund_keeps_the_payment_row_rather_than_erasing_it()
    {
        var payment = NewPayment(500m);
        payment.MarkCompleted(_clock.UtcNow);

        payment.Refund(_clock.AdvanceMinutes(5), "Wrong table");

        payment.Status.Should().Be(PaymentStatus.Refunded);
        payment.Amount.Amount.Should().Be(500m);
        payment.RefundedAt.Should().NotBeNull();
        payment.CountsTowardsSettlement.Should().BeFalse();
    }

    [Fact]
    public void The_idempotency_key_travels_with_the_payment()
        => NewPayment(100m, "abc-123").IdempotencyKey.Should().Be("abc-123");
}
