namespace STED.RestaurantOS.Application.Reports.Dtos;

/// <summary>Every report takes the same window. Inclusive start, exclusive end.</summary>
public sealed record ReportPeriod
{
    public required DateTimeOffset From { get; init; }

    public required DateTimeOffset To { get; init; }

    public static ReportPeriod Today(DateTimeOffset now)
    {
        var start = new DateTimeOffset(now.Date, now.Offset);
        return new ReportPeriod { From = start, To = start.AddDays(1) };
    }
}

/// <summary>
/// One waiter's service record over a period.
/// <para>
/// Revenue is attributed by <c>Order.WaiterId</c> — the waiter frozen on the
/// order when it was confirmed. A table handed over mid-service therefore splits
/// correctly between the two waiters instead of crediting whoever happened to
/// hold it last.
/// </para>
/// </summary>
public sealed record WaiterPerformanceDto
{
    public required Guid WaiterId { get; init; }

    public required string WaiterName { get; init; }

    public required string EmployeeCode { get; init; }

    public required int TablesServed { get; init; }

    public required int OrderCount { get; init; }

    public required int OrdersServed { get; init; }

    public required int GuestCount { get; init; }

    public required decimal Revenue { get; init; }

    public required string Currency { get; init; }

    public decimal AverageTicket => OrderCount == 0 ? 0m : decimal.Round(Revenue / OrderCount, 2);

    /// <summary>Average minutes a table stayed open under this waiter.</summary>
    public required int AverageTableMinutes { get; init; }

    public required int CancelledOrders { get; init; }

    public required int TransfersIn { get; init; }

    public required int TransfersOut { get; init; }
}

public sealed record TablePerformanceDto(
    Guid TableId,
    string TableNumber,
    string? ZoneName,
    int SessionCount,
    int GuestCount,
    int OrderCount,
    decimal Revenue,
    decimal AverageTicket,
    int AverageSessionMinutes,
    IReadOnlyList<string> WaitersWhoServed);

public sealed record ProductPerformanceDto(
    Guid ProductId,
    string ProductName,
    string? CategoryName,
    string StationCode,
    int QuantitySold,
    decimal Revenue,
    int OrderCount);

/// <summary>
/// How fast a station turns tickets around. The honest measure of whether the
/// kitchen or the bar is the bottleneck.
/// </summary>
public sealed record StationPerformanceDto(
    Guid StationId,
    string StationCode,
    int TicketCount,
    int AveragePreparationSeconds,
    int MedianPreparationSeconds,
    int LateTicketCount,
    double LatePercentage);

public sealed record SalesPointDto(DateTimeOffset Bucket, decimal Revenue, int OrderCount, int GuestCount);

public sealed record SalesReportDto(
    ReportPeriod Period,
    string Currency,
    decimal Revenue,
    decimal Tax,
    decimal Discounts,
    decimal ServiceCharges,
    int OrderCount,
    int GuestCount,
    decimal AverageTicket,
    IReadOnlyList<SalesPointDto> ByDay,
    IReadOnlyList<SalesPointDto> ByHour);

public sealed record CancellationDto(
    Guid OrderId,
    string OrderNumber,
    string TableNumber,
    string? WaiterName,
    string? CancelledByName,
    decimal Total,
    string? Reason,
    DateTimeOffset CancelledAt);

public sealed record PaymentReportRowDto(
    Guid PaymentId,
    string OrderNumber,
    string TableNumber,
    decimal Amount,
    Domain.Billing.PaymentMethod Method,
    Domain.Billing.PaymentStatus Status,
    string? CashierName,
    DateTimeOffset? PaidAt);

/// <summary>The manager's home screen: what is happening right now.</summary>
public sealed record AdminDashboardDto
{
    public required string Currency { get; init; }

    public required decimal RevenueToday { get; init; }

    public required int OrdersToday { get; init; }

    public required decimal AverageTicketToday { get; init; }

    public required int GuestsToday { get; init; }

    public required int TablesOccupied { get; init; }

    public required int TablesAvailable { get; init; }

    public required int OrdersInKitchen { get; init; }

    public required int OrdersAtBar { get; init; }

    public required int OrdersReady { get; init; }

    public required int OrdersServedToday { get; init; }

    public required int LateTickets { get; init; }

    public required int ActiveWaiters { get; init; }

    public required IReadOnlyList<SalesPointDto> RevenueByHour { get; init; }

    public required IReadOnlyList<ProductPerformanceDto> TopProducts { get; init; }
}
