using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Domain.Floor.Events;

public sealed record TableSessionOpenedDomainEvent(
    Guid RestaurantId,
    Guid TableSessionId,
    Guid TableId,
    Guid OpenedBy,
    int GuestCount,
    DateTimeOffset At) : DomainEvent(At);

public sealed record WaiterAssignedDomainEvent(
    Guid RestaurantId,
    Guid TableSessionId,
    Guid TableId,
    Guid WaiterId,
    Guid AssignedBy,
    DateTimeOffset At) : DomainEvent(At);

/// <summary>
/// Raised on every hand-over. Carries both waiters so the real-time layer can
/// notify each of them individually, and so the audit trail records who acted.
/// </summary>
public sealed record TableTransferredDomainEvent(
    Guid RestaurantId,
    Guid TableSessionId,
    Guid TableId,
    Guid PreviousWaiterId,
    Guid NewWaiterId,
    Guid ChangedBy,
    string? Reason,
    DateTimeOffset At) : DomainEvent(At);

public sealed record WaiterUnassignedDomainEvent(
    Guid RestaurantId,
    Guid TableSessionId,
    Guid TableId,
    Guid PreviousWaiterId,
    Guid ChangedBy,
    DateTimeOffset At) : DomainEvent(At);

public sealed record TableSessionClosedDomainEvent(
    Guid RestaurantId,
    Guid TableSessionId,
    Guid TableId,
    Guid ClosedBy,
    DateTimeOffset At) : DomainEvent(At);

public sealed record TableQrCodeRegeneratedDomainEvent(
    Guid RestaurantId,
    Guid TableId,
    Guid QrCodeId,
    Guid RegeneratedBy,
    DateTimeOffset At) : DomainEvent(At);
