using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Domain.Floor.Events;
using STED.RestaurantOS.Shared;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Domain.Floor;

/// <summary>
/// One episode of service on one table: a group of guests sits down, orders,
/// eats, pays, leaves. Every order of that period hangs off this session.
/// <para>
/// This is the most important aggregate in the system. It owns the service
/// assignments and their history, which means it is structurally impossible to
/// hand a table over without writing the audit trail: traceability stops being
/// a convention developers must remember and becomes a property of the type.
/// </para>
/// <para>
/// The database backs the same invariants with filtered unique indexes
/// (one open session per table, one active assignment per session), because
/// application code alone cannot win a race.
/// </para>
/// </summary>
public sealed class TableSession : AuditableAggregateRoot, ITenantEntity, IHasRowVersion
{
    private readonly List<ServiceAssignment> _assignments = [];
    private readonly List<ServiceAssignmentHistory> _history = [];

    private TableSession()
    {
    }

    private TableSession(
        Guid id,
        Guid restaurantId,
        Guid tableId,
        int sessionNumber,
        int guestCount,
        Guid openedBy,
        DateTimeOffset startedAt,
        string? notes)
        : base(id)
    {
        RestaurantId = restaurantId;
        TableId = tableId;
        SessionNumber = sessionNumber;
        GuestCount = guestCount;
        OpenedBy = openedBy;
        StartedAt = startedAt;
        Notes = notes;
        Status = TableSessionStatus.Open;
    }

    public Guid RestaurantId { get; private set; }

    public Guid TableId { get; private set; }

    /// <summary>Sequential per restaurant. What staff say out loud.</summary>
    public int SessionNumber { get; private set; }

    public TableSessionStatus Status { get; private set; }

    public int GuestCount { get; private set; }

    public DateTimeOffset StartedAt { get; private set; }

    public DateTimeOffset? EndedAt { get; private set; }

    public Guid OpenedBy { get; private set; }

    public Guid? ClosedBy { get; private set; }

    public string? Notes { get; private set; }

    public byte[]? RowVersion { get; private set; }

    public IReadOnlyCollection<ServiceAssignment> Assignments => _assignments.AsReadOnly();

    public IReadOnlyCollection<ServiceAssignmentHistory> History => _history.AsReadOnly();

    /// <summary>At most one, by invariant.</summary>
    public ServiceAssignment? CurrentAssignment
        => _assignments.SingleOrDefault(a => a.Status == ServiceAssignmentStatus.Active);

    /// <summary>
    /// The waiter an order created right now would be attributed to.
    /// Null is a legitimate answer: a guest may order from a table nobody has
    /// taken yet (RG-047).
    /// </summary>
    public Guid? CurrentWaiterId => CurrentAssignment?.WaiterId;

    public bool IsOpen => Status is TableSessionStatus.Open or TableSessionStatus.Active;

    public TimeSpan DurationAt(DateTimeOffset now) => (EndedAt ?? now) - StartedAt;

    public static TableSession Open(
        Guid restaurantId,
        Guid tableId,
        int sessionNumber,
        int guestCount,
        Guid openedBy,
        DateTimeOffset startedAt,
        string? notes = null)
    {
        Guard.NotEmpty(restaurantId);
        Guard.NotEmpty(tableId);
        Guard.InRange(guestCount, 1, 99);

        var session = new TableSession(
            Guid.CreateVersion7(),
            restaurantId,
            tableId,
            sessionNumber,
            guestCount,
            openedBy,
            startedAt,
            notes);

        session.Raise(new TableSessionOpenedDomainEvent(
            restaurantId, session.Id, tableId, openedBy, guestCount, startedAt));

        return session;
    }

    /// <summary>
    /// Gives the table to a waiter. Fails loudly if somebody already has it —
    /// the caller is expected to show "taken by X", not to silently overwrite.
    /// </summary>
    public ServiceAssignment AssignWaiter(
        Guid waiterId,
        Guid assignedBy,
        DateTimeOffset at,
        string? notes = null)
    {
        EnsureOpen();
        Guard.NotEmpty(waiterId);

        var current = CurrentAssignment;
        if (current is not null)
        {
            if (current.WaiterId == waiterId)
            {
                // Taking a table you already hold is a no-op, not an error:
                // it is what a double tap on a phone produces.
                return current;
            }

            throw new BusinessRuleViolationException(
                ErrorCodes.TableAlreadyAssigned,
                "This table is already being served by another waiter.");
        }

        var assignment = ServiceAssignment.Start(
            RestaurantId, TableId, Id, waiterId, assignedBy, at, notes);
        _assignments.Add(assignment);

        _history.Add(ServiceAssignmentHistory.Record(
            RestaurantId, TableId, Id, null, waiterId,
            ServiceAssignmentAction.Assigned, assignedBy, at, notes));

        Raise(new WaiterAssignedDomainEvent(RestaurantId, Id, TableId, waiterId, assignedBy, at));
        return assignment;
    }

    /// <summary>
    /// Hands the table to another waiter.
    /// <para>
    /// Orders already created keep their original waiter, for ever: this method
    /// touches nothing but the assignment chain. That is the whole point of
    /// freezing <c>Order.WaiterId</c> at confirmation time.
    /// </para>
    /// </summary>
    public ServiceAssignment TransferTo(
        Guid newWaiterId,
        Guid changedBy,
        bool actingAsManager,
        DateTimeOffset at,
        string? reason = null)
    {
        EnsureOpen();
        Guard.NotEmpty(newWaiterId);

        var current = CurrentAssignment
            ?? throw new BusinessRuleViolationException(
                ErrorCodes.TableNotAssigned,
                "This table has no active waiter, so there is nothing to transfer.");

        if (current.WaiterId == newWaiterId)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.SameWaiterTransfer,
                "The table is already served by this waiter.");
        }

        if (!actingAsManager && current.WaiterId != changedBy)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.NotTheAssignedWaiter,
                "Only the waiter currently serving this table, or a manager, can transfer it.");
        }

        var previousWaiterId = current.WaiterId;
        current.End(at, transferred: true);

        var assignment = ServiceAssignment.Start(
            RestaurantId, TableId, Id, newWaiterId, changedBy, at, reason);
        _assignments.Add(assignment);

        var action = previousWaiterId == changedBy
            ? ServiceAssignmentAction.Transferred
            : ServiceAssignmentAction.Reassigned;

        _history.Add(ServiceAssignmentHistory.Record(
            RestaurantId, TableId, Id, previousWaiterId, newWaiterId,
            action, changedBy, at, reason));

        Raise(new TableTransferredDomainEvent(
            RestaurantId, Id, TableId, previousWaiterId, newWaiterId, changedBy, reason, at));

        return assignment;
    }

    /// <summary>Releases the table without giving it to anyone.</summary>
    public void UnassignWaiter(Guid changedBy, DateTimeOffset at, string? reason = null)
    {
        EnsureOpen();

        var current = CurrentAssignment
            ?? throw new BusinessRuleViolationException(
                ErrorCodes.TableNotAssigned,
                "This table has no active waiter.");

        var previousWaiterId = current.WaiterId;
        current.End(at, transferred: false);

        _history.Add(ServiceAssignmentHistory.Record(
            RestaurantId, TableId, Id, previousWaiterId, null,
            ServiceAssignmentAction.Unassigned, changedBy, at, reason));

        Raise(new WaiterUnassignedDomainEvent(RestaurantId, Id, TableId, previousWaiterId, changedBy, at));
    }

    /// <summary>
    /// Answers "who was responsible for this table at 20:15?" straight from the
    /// aggregate. The same question is answered by an indexed SQL query for
    /// reporting; an integration test asserts both agree.
    /// </summary>
    public Guid? WaiterAt(DateTimeOffset instant)
        => _assignments
            .Where(a => a.CoversInstant(instant))
            .OrderByDescending(a => a.AssignedAt)
            .Select(a => (Guid?)a.WaiterId)
            .FirstOrDefault();

    /// <summary>Called when the first order is confirmed on this session.</summary>
    public void MarkActive()
    {
        if (Status == TableSessionStatus.Open)
        {
            Status = TableSessionStatus.Active;
        }
    }

    public void ChangeGuestCount(int guestCount)
    {
        EnsureOpen();
        GuestCount = Guard.InRange(guestCount, 1, 99);
    }

    public void UpdateNotes(string? notes)
    {
        EnsureOpen();
        Notes = notes;
    }

    /// <summary>
    /// Closes the session. The caller supplies whether unsettled orders remain,
    /// because orders live in another aggregate and this one must not query the
    /// database to decide.
    /// </summary>
    public void Close(Guid closedBy, DateTimeOffset at, bool hasUnsettledOrders)
    {
        EnsureOpen();

        if (hasUnsettledOrders)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.SessionHasUnpaidOrders,
                "This session still has orders that are neither closed nor cancelled.");
        }

        var current = CurrentAssignment;
        if (current is not null)
        {
            current.End(at, transferred: false);
            _history.Add(ServiceAssignmentHistory.Record(
                RestaurantId, TableId, Id, current.WaiterId, null,
                ServiceAssignmentAction.Unassigned, closedBy, at, "Session closed"));
        }

        Status = TableSessionStatus.Closed;
        ClosedBy = closedBy;
        EndedAt = at;

        Raise(new TableSessionClosedDomainEvent(RestaurantId, Id, TableId, closedBy, at));
    }

    /// <summary>Voids a session opened by mistake. Only allowed while it has no orders.</summary>
    public void Cancel(Guid cancelledBy, DateTimeOffset at, bool hasAnyOrders)
    {
        EnsureOpen();

        if (hasAnyOrders)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.SessionHasUnpaidOrders,
                "A session that already carries orders cannot be cancelled; close it instead.");
        }

        foreach (var assignment in _assignments.Where(a => a.IsActive))
        {
            assignment.End(at, transferred: false);
        }

        Status = TableSessionStatus.Cancelled;
        ClosedBy = cancelledBy;
        EndedAt = at;
    }

    private void EnsureOpen()
    {
        if (!IsOpen)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.SessionClosed,
                "This table session is closed and can no longer be modified.");
        }
    }
}
