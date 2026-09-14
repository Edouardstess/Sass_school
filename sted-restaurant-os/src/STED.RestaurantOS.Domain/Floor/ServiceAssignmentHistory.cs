using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Domain.Floor;

/// <summary>
/// Append-only trace of every hand-over. Never updated, never deleted (RG-033).
/// <para>
/// It duplicates information already derivable from the assignments, and that is
/// on purpose: it is the evidence trail. It answers "who was responsible for
/// table 8 at 20:15, and who moved it?" even if the live assignment rows were
/// ever to be corrupted, and an integration test asserts the two agree.
/// </para>
/// </summary>
public sealed class ServiceAssignmentHistory : Entity, ITenantEntity
{
    private ServiceAssignmentHistory()
    {
    }

    private ServiceAssignmentHistory(
        Guid id,
        Guid restaurantId,
        Guid tableId,
        Guid tableSessionId,
        Guid? previousWaiterId,
        Guid? newWaiterId,
        ServiceAssignmentAction action,
        Guid changedBy,
        DateTimeOffset changedAt,
        string? reason)
        : base(id)
    {
        RestaurantId = restaurantId;
        TableId = tableId;
        TableSessionId = tableSessionId;
        PreviousWaiterId = previousWaiterId;
        NewWaiterId = newWaiterId;
        Action = action;
        ChangedBy = changedBy;
        ChangedAt = changedAt;
        Reason = reason;
    }

    public Guid RestaurantId { get; private set; }

    public Guid TableId { get; private set; }

    public Guid TableSessionId { get; private set; }

    public Guid? PreviousWaiterId { get; private set; }

    public Guid? NewWaiterId { get; private set; }

    public ServiceAssignmentAction Action { get; private set; }

    public Guid ChangedBy { get; private set; }

    public DateTimeOffset ChangedAt { get; private set; }

    public string? Reason { get; private set; }

    internal static ServiceAssignmentHistory Record(
        Guid restaurantId,
        Guid tableId,
        Guid tableSessionId,
        Guid? previousWaiterId,
        Guid? newWaiterId,
        ServiceAssignmentAction action,
        Guid changedBy,
        DateTimeOffset changedAt,
        string? reason)
        => new(
            Guid.CreateVersion7(),
            restaurantId,
            tableId,
            tableSessionId,
            previousWaiterId,
            newWaiterId,
            action,
            changedBy,
            changedAt,
            reason);
}
