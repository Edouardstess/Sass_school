namespace STED.RestaurantOS.Domain.Floor;

public enum TableStatus
{
    Available = 0,
    Occupied = 1,
    Reserved = 2,
    Cleaning = 3,
    OutOfService = 4,
}

public enum TableSessionStatus
{
    /// <summary>Created, no order yet.</summary>
    Open = 0,

    /// <summary>At least one order has been placed.</summary>
    Active = 1,

    /// <summary>Settled and finished. Immutable.</summary>
    Closed = 2,

    /// <summary>Opened by mistake and voided. Immutable.</summary>
    Cancelled = 3,
}

public enum ServiceAssignmentStatus
{
    /// <summary>The waiter currently responsible. At most one per session.</summary>
    Active = 0,

    /// <summary>Ended normally (release or session closure).</summary>
    Ended = 1,

    /// <summary>Ended because the table was handed to another waiter.</summary>
    Transferred = 2,
}

public enum ServiceAssignmentAction
{
    Assigned = 0,
    Transferred = 1,
    Unassigned = 2,

    /// <summary>A manager moved the table without being the active waiter.</summary>
    Reassigned = 3,
}
