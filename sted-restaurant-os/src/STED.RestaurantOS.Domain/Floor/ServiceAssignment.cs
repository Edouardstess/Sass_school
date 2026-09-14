using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Domain.Floor;

/// <summary>
/// "This waiter is responsible for this table, from this moment until that one."
/// <para>
/// Deliberately NOT an aggregate root. It belongs to <see cref="TableSession"/>
/// because the invariant "at most one active assignment per session" has to be
/// checked somewhere that sees all of them at once. Making it independent would
/// let two concurrent requests each create an active assignment without any
/// aggregate noticing.
/// </para>
/// </summary>
public sealed class ServiceAssignment : Entity, ITenantEntity
{
    private ServiceAssignment()
    {
    }

    private ServiceAssignment(
        Guid id,
        Guid restaurantId,
        Guid tableId,
        Guid tableSessionId,
        Guid waiterId,
        Guid assignedBy,
        DateTimeOffset assignedAt,
        string? notes)
        : base(id)
    {
        RestaurantId = restaurantId;
        TableId = tableId;
        TableSessionId = tableSessionId;
        WaiterId = waiterId;
        AssignedBy = assignedBy;
        AssignedAt = assignedAt;
        Notes = notes;
        Status = ServiceAssignmentStatus.Active;
    }

    public Guid RestaurantId { get; private set; }

    public Guid TableId { get; private set; }

    public Guid TableSessionId { get; private set; }

    public Guid WaiterId { get; private set; }

    public ServiceAssignmentStatus Status { get; private set; }

    public DateTimeOffset AssignedAt { get; private set; }

    public DateTimeOffset? UnassignedAt { get; private set; }

    public Guid AssignedBy { get; private set; }

    public string? Notes { get; private set; }

    public bool IsActive => Status == ServiceAssignmentStatus.Active;

    /// <summary>Was this waiter responsible at the given instant?</summary>
    public bool CoversInstant(DateTimeOffset instant)
        => AssignedAt <= instant && (UnassignedAt is null || UnassignedAt > instant);

    public TimeSpan DurationAt(DateTimeOffset now) => (UnassignedAt ?? now) - AssignedAt;

    internal static ServiceAssignment Start(
        Guid restaurantId,
        Guid tableId,
        Guid tableSessionId,
        Guid waiterId,
        Guid assignedBy,
        DateTimeOffset assignedAt,
        string? notes)
        => new(
            Guid.CreateVersion7(),
            restaurantId,
            tableId,
            tableSessionId,
            waiterId,
            assignedBy,
            assignedAt,
            notes);

    internal void End(DateTimeOffset at, bool transferred)
    {
        Status = transferred ? ServiceAssignmentStatus.Transferred : ServiceAssignmentStatus.Ended;
        UnassignedAt = at;
    }
}
