using STED.RestaurantOS.Domain.Billing;

namespace STED.RestaurantOS.Application.Billing.Dtos;

/// <summary>What the cashier reads out loud before taking money.</summary>
public sealed record BillDto
{
    public required Guid TableSessionId { get; init; }

    public required Guid TableId { get; init; }

    public required string TableNumber { get; init; }

    public required int GuestCount { get; init; }

    public string? WaiterName { get; init; }

    public required DateTimeOffset StartedAt { get; init; }

    public required string Currency { get; init; }

    public required decimal Subtotal { get; init; }

    public required decimal TaxAmount { get; init; }

    public required decimal DiscountAmount { get; init; }

    public required decimal ServiceChargeAmount { get; init; }

    public required decimal Total { get; init; }

    public required decimal Paid { get; init; }

    public decimal Outstanding => Total - Paid;

    public bool IsSettled => Outstanding <= 0m;

    public required IReadOnlyList<BillOrderDto> Orders { get; init; }

    public required IReadOnlyList<PaymentDto> Payments { get; init; }
}

public sealed record BillOrderDto(
    Guid OrderId,
    string OrderNumber,
    Domain.Ordering.OrderStatus Status,
    decimal Total,
    DateTimeOffset CreatedAt,
    IReadOnlyList<BillLineDto> Lines);

public sealed record BillLineDto(string ProductName, int Quantity, decimal UnitPrice, decimal LineTotal);

public sealed record PaymentDto(
    Guid Id,
    Guid OrderId,
    decimal Amount,
    string Currency,
    PaymentMethod Method,
    PaymentStatus Status,
    DateTimeOffset? PaidAt,
    Guid ProcessedBy,
    string? ProcessedByName,
    string? TransactionReference);

public sealed record TakePaymentRequest
{
    /// <summary>
    /// Pay one order, or leave null to settle the whole table in order of age.
    /// </summary>
    public Guid? OrderId { get; init; }

    public Guid? TableSessionId { get; init; }

    public required decimal Amount { get; init; }

    public required PaymentMethod Method { get; init; }

    /// <summary>Provider reference for non-cash methods. Ignored for cash.</summary>
    public string? TransactionReference { get; init; }
}

public sealed record RefundRequest
{
    public required string Reason { get; init; }
}

/// <summary>A table waiting to pay, as the cashier's queue shows it.</summary>
public sealed record PendingSessionDto(
    Guid TableSessionId,
    Guid TableId,
    string TableNumber,
    string? WaiterName,
    int GuestCount,
    DateTimeOffset StartedAt,
    int OrderCount,
    decimal Total,
    decimal Paid,
    decimal Outstanding,
    bool BillRequested);

/// <summary>Everything a printed receipt needs, resolved once server-side.</summary>
public sealed record ReceiptDto(
    Guid PaymentId,
    string RestaurantName,
    string? Address,
    string? Phone,
    string? Header,
    string? Footer,
    string TableNumber,
    string? WaiterName,
    string CashierName,
    DateTimeOffset PaidAt,
    string Currency,
    decimal Subtotal,
    decimal TaxAmount,
    decimal DiscountAmount,
    decimal ServiceChargeAmount,
    decimal Total,
    decimal AmountPaid,
    PaymentMethod Method,
    IReadOnlyList<BillLineDto> Lines);
