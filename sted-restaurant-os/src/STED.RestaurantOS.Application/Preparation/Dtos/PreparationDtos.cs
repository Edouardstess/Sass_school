using STED.RestaurantOS.Domain.Preparation;

namespace STED.RestaurantOS.Application.Preparation.Dtos;

/// <summary>
/// One card on a kitchen or bar screen.
/// <para>
/// Carries no prices: a cook needs the table, the waiter, what to make and how
/// long it has been waiting. Everything here is meant to be legible from two
/// metres away.
/// </para>
/// </summary>
public sealed record PreparationTicketDto
{
    public required Guid Id { get; init; }

    public required string TicketNumber { get; init; }

    public required Guid OrderId { get; init; }

    public required string OrderNumber { get; init; }

    public required Guid StationId { get; init; }

    public required string StationCode { get; init; }

    public required string TableNumber { get; init; }

    public string? WaiterName { get; init; }

    public required PreparationTicketStatus Status { get; init; }

    public required DateTimeOffset CreatedAt { get; init; }

    public DateTimeOffset? AcceptedAt { get; init; }

    public DateTimeOffset? ReadyAt { get; init; }

    /// <summary>Seconds since the ticket landed. The client counts on from here.</summary>
    public required int ElapsedSeconds { get; init; }

    public required bool IsLate { get; init; }

    public required bool IsWarning { get; init; }

    public string? OrderNotes { get; init; }

    public required IReadOnlyList<PreparationTicketItemDto> Items { get; init; }
}

public sealed record PreparationTicketItemDto(
    Guid Id,
    string ProductName,
    int Quantity,
    string? Notes,
    string? ModifiersSummary,
    PreparationTicketItemStatus Status);

/// <summary>
/// The whole screen in one response.
/// <para>
/// This is what a client calls after a dropped connection, and every fifteen
/// seconds regardless. Real-time push is an accelerator; this endpoint is the
/// truth. A kitchen screen that silently stops updating stops the service.
/// </para>
/// </summary>
public sealed record StationSnapshotDto
{
    public required Guid StationId { get; init; }

    public required string StationCode { get; init; }

    public required DateTimeOffset ServerTime { get; init; }

    public required IReadOnlyList<PreparationTicketDto> New { get; init; }

    public required IReadOnlyList<PreparationTicketDto> InPreparation { get; init; }

    public required IReadOnlyList<PreparationTicketDto> Ready { get; init; }

    public int LateCount { get; init; }
}
