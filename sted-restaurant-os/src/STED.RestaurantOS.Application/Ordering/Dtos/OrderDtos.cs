using STED.RestaurantOS.Domain.Ordering;

namespace STED.RestaurantOS.Application.Ordering.Dtos;

/// <summary>
/// What the client sends: product ids, quantities, chosen options, a note.
/// <para>
/// Note what is absent: prices. Anything a client says about money is ignored,
/// because it is the one thing it has an incentive to lie about. Every amount is
/// read from the database inside the confirmation transaction.
/// </para>
/// </summary>
public sealed record CartLineRequest
{
    public required Guid ProductId { get; init; }

    public required int Quantity { get; init; }

    public string? Notes { get; init; }

    public IReadOnlyList<Guid> ModifierOptionIds { get; init; } = [];
}

public sealed record PlaceOrderRequest
{
    public required IReadOnlyList<CartLineRequest> Lines { get; init; }

    public string? Notes { get; init; }
}

/// <summary>
/// A server-side quote. Lets the guest app show a trustworthy total before
/// committing, and surfaces anything that has just sold out.
/// </summary>
public sealed record CartQuoteDto
{
    public required decimal Subtotal { get; init; }

    public required decimal TaxAmount { get; init; }

    public required decimal ServiceChargeAmount { get; init; }

    public required decimal Total { get; init; }

    public required string Currency { get; init; }

    public required IReadOnlyList<CartQuoteLineDto> Lines { get; init; }

    /// <summary>Products that cannot be ordered right now, with the reason.</summary>
    public IReadOnlyList<UnavailableLineDto> Unavailable { get; init; } = [];

    public bool CanBeOrdered => Unavailable.Count == 0 && Lines.Count > 0;
}

public sealed record CartQuoteLineDto(
    Guid ProductId,
    string ProductName,
    int Quantity,
    decimal UnitPrice,
    decimal ModifiersTotal,
    decimal LineSubtotal,
    decimal LineTax,
    decimal LineTotal,
    IReadOnlyList<string> ModifierLabels);

public sealed record UnavailableLineDto(Guid ProductId, string ProductName, string Reason);

public sealed record OrderItemDto(
    Guid Id,
    Guid ProductId,
    string ProductName,
    int Quantity,
    decimal UnitPrice,
    decimal LineTotal,
    string StationCode,
    OrderItemStatus Status,
    string? Notes,
    IReadOnlyList<string> Modifiers);

public sealed record OrderDto
{
    public required Guid Id { get; init; }

    public required string OrderNumber { get; init; }

    public required Guid TableId { get; init; }

    public required string TableNumber { get; init; }

    public required Guid TableSessionId { get; init; }

    public Guid? WaiterId { get; init; }

    public string? WaiterName { get; init; }

    public required OrderStatus Status { get; init; }

    public required OrderSource Source { get; init; }

    public required decimal Subtotal { get; init; }

    public required decimal TaxAmount { get; init; }

    public required decimal DiscountAmount { get; init; }

    public required decimal ServiceChargeAmount { get; init; }

    public required decimal Total { get; init; }

    public required string Currency { get; init; }

    public string? Notes { get; init; }

    public required DateTimeOffset CreatedAt { get; init; }

    public DateTimeOffset? ConfirmedAt { get; init; }

    public DateTimeOffset? ReadyAt { get; init; }

    public DateTimeOffset? ServedAt { get; init; }

    public Guid? ServedBy { get; init; }

    public string? ServedByName { get; init; }

    public required IReadOnlyList<OrderItemDto> Items { get; init; }

    /// <summary>Minutes since the order was confirmed. Drives the waiting badge.</summary>
    public int? WaitingMinutes { get; init; }
}

public sealed record OrderStatusHistoryDto(
    OrderStatus? PreviousStatus,
    OrderStatus NewStatus,
    Guid? ChangedBy,
    string? ChangedByName,
    DateTimeOffset ChangedAt,
    string? Note);

public sealed record CancelOrderRequest
{
    public required string Reason { get; init; }
}

public sealed record ApplyDiscountRequest
{
    public required decimal Percentage { get; init; }

    public required string Reason { get; init; }
}

public sealed record OrderFilter
{
    public OrderStatus? Status { get; init; }

    public Guid? TableId { get; init; }

    public Guid? WaiterId { get; init; }

    public Guid? TableSessionId { get; init; }

    public DateTimeOffset? From { get; init; }

    public DateTimeOffset? To { get; init; }
}
