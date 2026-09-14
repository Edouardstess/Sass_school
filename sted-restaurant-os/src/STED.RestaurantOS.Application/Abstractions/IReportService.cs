using STED.RestaurantOS.Application.Reports.Dtos;

namespace STED.RestaurantOS.Application.Abstractions;

/// <summary>
/// Read-only analytics.
/// <para>
/// Every method here aggregates in SQL and returns a projection. None of them
/// loads orders into memory to add them up: a venue does fifty thousand orders a
/// year, and a report that works in the first month must still work in the
/// third year.
/// </para>
/// </summary>
public interface IReportService
{
    Task<SalesReportDto> GetSalesAsync(ReportPeriod period, CancellationToken ct);

    /// <summary>Answers "how did each waiter do?" — and by extension questions 2, 3 and 4.</summary>
    Task<IReadOnlyList<WaiterPerformanceDto>> GetWaiterPerformanceAsync(
        ReportPeriod period,
        Guid? waiterId,
        CancellationToken ct);

    Task<IReadOnlyList<TablePerformanceDto>> GetTablePerformanceAsync(
        ReportPeriod period,
        CancellationToken ct);

    Task<IReadOnlyList<ProductPerformanceDto>> GetProductPerformanceAsync(
        ReportPeriod period,
        int top,
        CancellationToken ct);

    Task<IReadOnlyList<StationPerformanceDto>> GetStationPerformanceAsync(
        ReportPeriod period,
        CancellationToken ct);

    Task<IReadOnlyList<CancellationDto>> GetCancellationsAsync(ReportPeriod period, CancellationToken ct);

    Task<IReadOnlyList<PaymentReportRowDto>> GetPaymentsAsync(ReportPeriod period, CancellationToken ct);

    Task<AdminDashboardDto> GetDashboardAsync(CancellationToken ct);
}
