using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Reports.Dtos;
using STED.RestaurantOS.Application.Security;
using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.API.Controllers;

/// <summary>
/// Management reporting. Every route needs <c>Reports.View</c>, which only
/// managers and administrators hold: how each waiter performed is not shop-floor
/// information.
/// </summary>
[Route("api/v1/reports")]
[Authorize(Policy = Permissions.ReportsView)]
public sealed class ReportsController : ApiControllerBase
{
    private readonly IReportService _reports;
    private readonly IDateTimeProvider _clock;

    public ReportsController(IReportService reports, IDateTimeProvider clock)
    {
        _reports = reports;
        _clock = clock;
    }

    [HttpGet("sales")]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    public async Task<IActionResult> GetSales(
        [FromQuery] DateTimeOffset? from,
        [FromQuery] DateTimeOffset? to,
        CancellationToken ct)
        => Payload(await _reports.GetSalesAsync(Period(from, to), ct));

    /// <summary>
    /// How each waiter performed: tables, orders, guests, revenue, average
    /// ticket, average table time, cancellations and hand-overs.
    /// </summary>
    [HttpGet("waiters")]
    public async Task<IActionResult> GetWaiterPerformance(
        [FromQuery] DateTimeOffset? from,
        [FromQuery] DateTimeOffset? to,
        CancellationToken ct)
        => Payload(await _reports.GetWaiterPerformanceAsync(Period(from, to), null, ct));

    [HttpGet("waiters/{waiterId:guid}")]
    public async Task<IActionResult> GetWaiter(
        Guid waiterId,
        [FromQuery] DateTimeOffset? from,
        [FromQuery] DateTimeOffset? to,
        CancellationToken ct)
        => Payload(await _reports.GetWaiterPerformanceAsync(Period(from, to), waiterId, ct));

    [HttpGet("tables")]
    public async Task<IActionResult> GetTablePerformance(
        [FromQuery] DateTimeOffset? from,
        [FromQuery] DateTimeOffset? to,
        CancellationToken ct)
        => Payload(await _reports.GetTablePerformanceAsync(Period(from, to), ct));

    [HttpGet("products")]
    public async Task<IActionResult> GetProductPerformance(
        [FromQuery] DateTimeOffset? from,
        [FromQuery] DateTimeOffset? to,
        [FromQuery] int top = 20,
        CancellationToken ct = default)
        => Payload(await _reports.GetProductPerformanceAsync(Period(from, to), top, ct));

    /// <summary>Turnaround per station — the honest answer to "where is the bottleneck?".</summary>
    [HttpGet("stations")]
    public async Task<IActionResult> GetStationPerformance(
        [FromQuery] DateTimeOffset? from,
        [FromQuery] DateTimeOffset? to,
        CancellationToken ct)
        => Payload(await _reports.GetStationPerformanceAsync(Period(from, to), ct));

    [HttpGet("cancellations")]
    public async Task<IActionResult> GetCancellations(
        [FromQuery] DateTimeOffset? from,
        [FromQuery] DateTimeOffset? to,
        CancellationToken ct)
        => Payload(await _reports.GetCancellationsAsync(Period(from, to), ct));

    [HttpGet("payments")]
    public async Task<IActionResult> GetPayments(
        [FromQuery] DateTimeOffset? from,
        [FromQuery] DateTimeOffset? to,
        CancellationToken ct)
        => Payload(await _reports.GetPaymentsAsync(Period(from, to), ct));

    /// <summary>Default window is today, because that is what a manager asks for nine times out of ten.</summary>
    private ReportPeriod Period(DateTimeOffset? from, DateTimeOffset? to)
    {
        if (from is null && to is null)
        {
            return ReportPeriod.Today(_clock.UtcNow);
        }

        var start = from ?? _clock.UtcNow.AddDays(-30);
        var end = to ?? _clock.UtcNow;

        return new ReportPeriod { From = start, To = end <= start ? start.AddDays(1) : end };
    }
}

[Route("api/v1/dashboard")]
[Authorize]
public sealed class DashboardController : ApiControllerBase
{
    private readonly IReportService _reports;

    public DashboardController(IReportService reports) => _reports = reports;

    /// <summary>What is happening right now: revenue, covers, tables, screens, late tickets.</summary>
    [HttpGet("admin")]
    [Authorize(Policy = Permissions.ReportsView)]
    public async Task<IActionResult> GetAdminDashboard(CancellationToken ct)
        => Payload(await _reports.GetDashboardAsync(ct));
}
