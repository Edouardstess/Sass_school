using STED.RestaurantOS.Domain.Floor;

namespace STED.RestaurantOS.Application.Floor.Dtos;

public sealed record TableZoneDto(Guid Id, string Name, int DisplayOrder, bool IsActive, int TableCount);

/// <summary>
/// A table as the floor plan shows it: the furniture, plus who is on it right
/// now. The live part is what a waiter actually looks at.
/// </summary>
public sealed record TableDto
{
    public required Guid Id { get; init; }

    public required string Number { get; init; }

    public string? Name { get; init; }

    public required int Capacity { get; init; }

    public required TableStatus Status { get; init; }

    public Guid? ZoneId { get; init; }

    public string? ZoneName { get; init; }

    public bool HasActiveQrCode { get; init; }

    // --- live service state ---

    public Guid? CurrentSessionId { get; init; }

    public Guid? CurrentWaiterId { get; init; }

    public string? CurrentWaiterName { get; init; }

    public int? GuestCount { get; init; }

    public DateTimeOffset? SessionStartedAt { get; init; }

    public int OpenOrderCount { get; init; }

    public decimal SessionTotal { get; init; }

    /// <summary>Minutes since the party sat down. Drives the floor-plan colour.</summary>
    public int? SeatedMinutes { get; init; }
}

public sealed record CreateTableRequest
{
    public required string Number { get; init; }

    public string? Name { get; init; }

    public required int Capacity { get; init; }

    public Guid? ZoneId { get; init; }
}

public sealed record TakeTableRequest
{
    public required int GuestCount { get; init; }

    public string? Notes { get; init; }
}

public sealed record TransferTableRequest
{
    public required Guid NewWaiterId { get; init; }

    public string? Reason { get; init; }
}

public sealed record ReleaseTableRequest
{
    public string? Reason { get; init; }
}

public sealed record TableSessionDto
{
    public required Guid Id { get; init; }

    public required Guid TableId { get; init; }

    public required string TableNumber { get; init; }

    public required int SessionNumber { get; init; }

    public required TableSessionStatus Status { get; init; }

    public required int GuestCount { get; init; }

    public required DateTimeOffset StartedAt { get; init; }

    public DateTimeOffset? EndedAt { get; init; }

    public Guid? CurrentWaiterId { get; init; }

    public string? CurrentWaiterName { get; init; }

    public required int OrderCount { get; init; }

    public required decimal Total { get; init; }

    public required decimal Paid { get; init; }

    public decimal Outstanding => Total - Paid;

    public string? Notes { get; init; }
}

/// <summary>One line of the hand-over trail. Never edited, never deleted.</summary>
public sealed record ServiceAssignmentHistoryDto(
    Guid Id,
    Guid TableId,
    string TableNumber,
    Guid TableSessionId,
    Guid? PreviousWaiterId,
    string? PreviousWaiterName,
    Guid? NewWaiterId,
    string? NewWaiterName,
    ServiceAssignmentAction Action,
    Guid ChangedBy,
    string? ChangedByName,
    DateTimeOffset ChangedAt,
    string? Reason);

/// <summary>
/// The answer to "who was responsible for table 8 at 20:15?", with the evidence
/// it was derived from.
/// </summary>
public sealed record ResponsibleWaiterDto(
    Guid TableId,
    string TableNumber,
    DateTimeOffset At,
    Guid? WaiterId,
    string? WaiterName,
    Guid? TableSessionId,
    DateTimeOffset? AssignedAt,
    DateTimeOffset? UnassignedAt);

/// <summary>What gets printed and stuck on the table. Returned exactly once.</summary>
public sealed record QrCodeIssuedDto(
    Guid QrCodeId,
    Guid TableId,
    string TableNumber,
    string OrderUrl,
    string ClearToken,
    DateTimeOffset CreatedAt,
    DateTimeOffset? ExpiresAt);
