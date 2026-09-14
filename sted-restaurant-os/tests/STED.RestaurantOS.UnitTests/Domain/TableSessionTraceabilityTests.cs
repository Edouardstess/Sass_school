using FluentAssertions;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Domain.Floor.Events;
using STED.RestaurantOS.UnitTests.Fixtures;
using Xunit;

namespace STED.RestaurantOS.UnitTests.Domain;

/// <summary>
/// The traceability contract. These tests exist so that the questions the venue
/// owner actually asks — who had table 8 at 20:15, who handed it over, who
/// closed it — keep having an answer as the code changes.
/// </summary>
public sealed class TableSessionTraceabilityTests
{
    private readonly TestClock _clock = TestClock.AtServiceEvening();

    [Fact]
    public void Taking_a_table_records_the_assignment_and_its_history()
    {
        var session = Build.Session(_clock.UtcNow);

        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);

        session.CurrentWaiterId.Should().Be(Build.Jean);
        session.History.Should().ContainSingle()
            .Which.Action.Should().Be(ServiceAssignmentAction.Assigned);
    }

    /// <summary>Business test 1: two waiters cannot hold the same table.</summary>
    [Fact]
    public void A_second_waiter_cannot_take_a_table_that_is_already_served()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);

        var act = () => session.AssignWaiter(Build.Marie, Build.Marie, _clock.UtcNow);

        act.Should().Throw<BusinessRuleViolationException>()
            .Which.Code.Should().Be(STED.RestaurantOS.Shared.Errors.ErrorCodes.TableAlreadyAssigned);
    }

    [Fact]
    public void Taking_a_table_you_already_hold_is_a_no_op_not_an_error()
    {
        var session = Build.Session(_clock.UtcNow);
        var first = session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);

        // What a double tap on a phone in a busy room produces.
        var second = session.AssignWaiter(Build.Jean, Build.Jean, _clock.AdvanceMinutes(1));

        second.Id.Should().Be(first.Id);
        session.Assignments.Should().ContainSingle();
    }

    /// <summary>Business test 7: every hand-over is recorded.</summary>
    [Fact]
    public void A_transfer_ends_the_old_assignment_opens_a_new_one_and_writes_history()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);

        var at = _clock.AdvanceMinutes(45);
        session.TransferTo(Build.Marie, Build.Jean, actingAsManager: false, at, "End of shift");

        session.CurrentWaiterId.Should().Be(Build.Marie);
        session.Assignments.Should().HaveCount(2);

        session.Assignments.Single(a => a.WaiterId == Build.Jean)
            .Status.Should().Be(ServiceAssignmentStatus.Transferred);

        var record = session.History.Last();
        record.Action.Should().Be(ServiceAssignmentAction.Transferred);
        record.PreviousWaiterId.Should().Be(Build.Jean);
        record.NewWaiterId.Should().Be(Build.Marie);
        record.ChangedBy.Should().Be(Build.Jean);
        record.Reason.Should().Be("End of shift");
    }

    [Fact]
    public void A_waiter_cannot_transfer_a_table_that_is_not_theirs()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);

        var act = () => session.TransferTo(
            Build.Marie, changedBy: Build.Marie, actingAsManager: false, _clock.UtcNow);

        act.Should().Throw<BusinessRuleViolationException>()
            .Which.Code.Should().Be(STED.RestaurantOS.Shared.Errors.ErrorCodes.NotTheAssignedWaiter);
    }

    [Fact]
    public void A_manager_may_move_a_table_they_do_not_serve_and_it_is_logged_as_a_reassignment()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);

        session.TransferTo(Build.Marie, Build.Manager, actingAsManager: true, _clock.AdvanceMinutes(5));

        session.History.Last().Action.Should().Be(ServiceAssignmentAction.Reassigned);
        session.History.Last().ChangedBy.Should().Be(Build.Manager);
    }

    [Fact]
    public void Transferring_a_table_to_the_waiter_who_already_has_it_is_refused()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);

        var act = () => session.TransferTo(Build.Jean, Build.Jean, actingAsManager: false, _clock.UtcNow);

        act.Should().Throw<BusinessRuleViolationException>();
    }

    /// <summary>
    /// The question from the specification, answered straight from the aggregate:
    /// "who was responsible for this table at 20:15?"
    /// </summary>
    [Fact]
    public void The_responsible_waiter_at_a_past_instant_can_be_recovered()
    {
        var nineteen = _clock.UtcNow;                   // 19:00
        var session = Build.Session(nineteen);
        session.AssignWaiter(Build.Jean, Build.Jean, nineteen);

        var nineteenFortyFive = _clock.AdvanceMinutes(45);
        session.TransferTo(Build.Marie, Build.Jean, actingAsManager: false, nineteenFortyFive);

        session.WaiterAt(nineteen.AddMinutes(15)).Should().Be(Build.Jean);   // 19:15
        session.WaiterAt(nineteen.AddMinutes(75)).Should().Be(Build.Marie);  // 20:15
        session.WaiterAt(nineteen.AddMinutes(-5)).Should().BeNull();         // before service
    }

    [Fact]
    public void The_history_and_the_assignments_tell_the_same_story()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);
        var transferAt = _clock.AdvanceMinutes(45);
        session.TransferTo(Build.Marie, Build.Jean, actingAsManager: false, transferAt);

        var instant = transferAt.AddMinutes(10);

        var fromAssignments = session.WaiterAt(instant);
        var fromHistory = session.History
            .Where(h => h.ChangedAt <= instant)
            .OrderByDescending(h => h.ChangedAt)
            .Select(h => h.NewWaiterId)
            .FirstOrDefault();

        fromAssignments.Should().Be(fromHistory);
    }

    [Fact]
    public void Releasing_a_table_leaves_the_session_open_with_no_waiter()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);

        session.UnassignWaiter(Build.Jean, _clock.AdvanceMinutes(10), "Going home");

        session.CurrentWaiterId.Should().BeNull();
        session.IsOpen.Should().BeTrue();
        session.History.Last().Action.Should().Be(ServiceAssignmentAction.Unassigned);
    }

    [Fact]
    public void A_session_with_unsettled_orders_cannot_be_closed()
    {
        var session = Build.Session(_clock.UtcNow);

        var act = () => session.Close(Build.Jean, _clock.UtcNow, hasUnsettledOrders: true);

        act.Should().Throw<BusinessRuleViolationException>()
            .Which.Code.Should().Be(STED.RestaurantOS.Shared.Errors.ErrorCodes.SessionHasUnpaidOrders);
    }

    [Fact]
    public void Closing_a_session_ends_the_active_assignment_and_records_it()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);

        var closedAt = _clock.AdvanceMinutes(90);
        session.Close(Build.Manager, closedAt, hasUnsettledOrders: false);

        session.Status.Should().Be(TableSessionStatus.Closed);
        session.ClosedBy.Should().Be(Build.Manager);
        session.EndedAt.Should().Be(closedAt);
        session.CurrentWaiterId.Should().BeNull();
        session.History.Last().Action.Should().Be(ServiceAssignmentAction.Unassigned);
    }

    [Fact]
    public void A_closed_session_refuses_any_further_change()
    {
        var session = Build.Session(_clock.UtcNow);
        session.Close(Build.Jean, _clock.UtcNow, hasUnsettledOrders: false);

        var act = () => session.AssignWaiter(Build.Marie, Build.Marie, _clock.UtcNow);

        act.Should().Throw<BusinessRuleViolationException>()
            .Which.Code.Should().Be(STED.RestaurantOS.Shared.Errors.ErrorCodes.SessionClosed);
    }

    [Fact]
    public void How_long_the_table_was_open_is_answerable()
    {
        var session = Build.Session(_clock.UtcNow);
        var closedAt = _clock.AdvanceMinutes(75);
        session.Close(Build.Jean, closedAt, hasUnsettledOrders: false);

        session.DurationAt(_clock.UtcNow).Should().Be(TimeSpan.FromMinutes(75));
    }

    [Fact]
    public void A_transfer_raises_an_event_naming_both_waiters_and_the_actor()
    {
        var session = Build.Session(_clock.UtcNow);
        session.AssignWaiter(Build.Jean, Build.Jean, _clock.UtcNow);
        session.TransferTo(Build.Marie, Build.Jean, actingAsManager: false, _clock.AdvanceMinutes(45));

        var transferred = session.DomainEvents.OfType<TableTransferredDomainEvent>().Single();

        transferred.PreviousWaiterId.Should().Be(Build.Jean);
        transferred.NewWaiterId.Should().Be(Build.Marie);
        transferred.ChangedBy.Should().Be(Build.Jean);
    }
}
