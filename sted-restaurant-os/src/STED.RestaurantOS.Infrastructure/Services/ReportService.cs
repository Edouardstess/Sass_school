using Microsoft.EntityFrameworkCore;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Reports.Dtos;
using STED.RestaurantOS.Domain.Billing;
using STED.RestaurantOS.Domain.Catalog;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.Preparation;
using STED.RestaurantOS.Infrastructure.Persistence;

namespace STED.RestaurantOS.Infrastructure.Services;

public sealed class ReportService : IReportService
{
    private readonly AppDbContext _db;
    private readonly IDateTimeProvider _clock;

    public ReportService(AppDbContext db, IDateTimeProvider clock)
    {
        _db = db;
        _clock = clock;
    }

    public async Task<SalesReportDto> GetSalesAsync(ReportPeriod period, CancellationToken ct)
    {
        var orders = RevenueOrders(period);

        // One round trip for the headline figures, aggregated by the database.
        var totals = await orders
            .GroupBy(_ => 1)
            .Select(g => new
            {
                Revenue = g.Sum(o => o.Total.Amount),
                Tax = g.Sum(o => o.TaxAmount.Amount),
                Discounts = g.Sum(o => o.DiscountAmount.Amount),
                Service = g.Sum(o => o.ServiceChargeAmount.Amount),
                OrderCount = g.Count(),
            })
            .FirstOrDefaultAsync(ct);

        var byDay = await orders
            .GroupBy(o => o.CreatedAt.Date)
            .Select(g => new { Day = g.Key, Revenue = g.Sum(o => o.Total.Amount), Count = g.Count() })
            .OrderBy(x => x.Day)
            .ToListAsync(ct);

        var byHour = await orders
            .GroupBy(o => o.CreatedAt.Hour)
            .Select(g => new { Hour = g.Key, Revenue = g.Sum(o => o.Total.Amount), Count = g.Count() })
            .OrderBy(x => x.Hour)
            .ToListAsync(ct);

        var guests = await _db.TableSessions
            .Where(s => s.StartedAt >= period.From && s.StartedAt < period.To)
            .SumAsync(s => (int?)s.GuestCount, ct) ?? 0;

        var currency = await CurrencyAsync(ct);
        var revenue = totals?.Revenue ?? 0m;
        var orderCount = totals?.OrderCount ?? 0;

        return new SalesReportDto(
            period,
            currency,
            revenue,
            totals?.Tax ?? 0m,
            totals?.Discounts ?? 0m,
            totals?.Service ?? 0m,
            orderCount,
            guests,
            orderCount == 0 ? 0m : decimal.Round(revenue / orderCount, 2),
            byDay.Select(d => new SalesPointDto(
                new DateTimeOffset(d.Day, TimeSpan.Zero), d.Revenue, d.Count, 0)).ToList(),
            byHour.Select(h => new SalesPointDto(
                period.From.Date.AddHours(h.Hour), h.Revenue, h.Count, 0)).ToList());
    }

    /// <inheritdoc />
    public async Task<IReadOnlyList<WaiterPerformanceDto>> GetWaiterPerformanceAsync(
        ReportPeriod period,
        Guid? waiterId,
        CancellationToken ct)
    {
        var currency = await CurrencyAsync(ct);

        var staff = await _db.StaffProfiles
            .AsNoTracking()
            .Where(s => waiterId == null || s.Id == waiterId)
            .Select(s => new { s.Id, s.DisplayName, EmployeeCode = s.EmployeeCode.Value })
            .ToListAsync(ct);

        // Revenue follows Order.WaiterId, the waiter frozen at confirmation.
        // A table handed over mid-service splits between both waiters, which is
        // the whole reason that column is not recomputed on transfer.
        var orderStats = await RevenueOrders(period)
            .Where(o => o.WaiterId != null)
            .GroupBy(o => o.WaiterId!.Value)
            .Select(g => new
            {
                WaiterId = g.Key,
                Revenue = g.Sum(o => o.Total.Amount),
                OrderCount = g.Count(),
                Served = g.Count(o => o.ServedAt != null),
            })
            .ToListAsync(ct);

        var cancellations = await _db.Orders
            .AsNoTracking()
            .Where(o => o.Status == OrderStatus.Cancelled
                        && o.CancelledAt >= period.From && o.CancelledAt < period.To
                        && o.WaiterId != null)
            .GroupBy(o => o.WaiterId!.Value)
            .Select(g => new { WaiterId = g.Key, Count = g.Count() })
            .ToListAsync(ct);

        // Table time comes from the assignments, not the orders: a waiter who
        // looked after a table that ordered nothing still worked it.
        var assignments = await _db.ServiceAssignments
            .Where(a => a.AssignedAt >= period.From && a.AssignedAt < period.To)
            .Select(a => new
            {
                a.WaiterId,
                a.TableId,
                a.TableSessionId,
                a.AssignedAt,
                a.UnassignedAt,
            })
            .ToListAsync(ct);

        var transfers = await _db.ServiceAssignmentHistory
            .Where(h => h.ChangedAt >= period.From && h.ChangedAt < period.To
                        && (h.Action == ServiceAssignmentAction.Transferred
                            || h.Action == ServiceAssignmentAction.Reassigned))
            .Select(h => new { h.PreviousWaiterId, h.NewWaiterId })
            .ToListAsync(ct);

        var guestsBySession = await _db.TableSessions
            .Where(s => s.StartedAt >= period.From && s.StartedAt < period.To)
            .Select(s => new { s.Id, s.GuestCount })
            .ToDictionaryAsync(s => s.Id, s => s.GuestCount, ct);

        var now = _clock.UtcNow;

        return staff.Select(person =>
        {
            var mine = assignments.Where(a => a.WaiterId == person.Id).ToList();
            var stats = orderStats.FirstOrDefault(s => s.WaiterId == person.Id);

            var minutes = mine
                .Select(a => ((a.UnassignedAt ?? now) - a.AssignedAt).TotalMinutes)
                .DefaultIfEmpty(0)
                .Average();

            return new WaiterPerformanceDto
            {
                WaiterId = person.Id,
                WaiterName = person.DisplayName,
                EmployeeCode = person.EmployeeCode,
                TablesServed = mine.Select(a => a.TableId).Distinct().Count(),
                OrderCount = stats?.OrderCount ?? 0,
                OrdersServed = stats?.Served ?? 0,
                GuestCount = mine
                    .Select(a => a.TableSessionId)
                    .Distinct()
                    .Sum(id => guestsBySession.GetValueOrDefault(id)),
                Revenue = stats?.Revenue ?? 0m,
                Currency = currency,
                AverageTableMinutes = (int)minutes,
                CancelledOrders = cancellations.FirstOrDefault(c => c.WaiterId == person.Id)?.Count ?? 0,
                TransfersIn = transfers.Count(t => t.NewWaiterId == person.Id),
                TransfersOut = transfers.Count(t => t.PreviousWaiterId == person.Id),
            };
        })
        .OrderByDescending(w => w.Revenue)
        .ToList();
    }

    public async Task<IReadOnlyList<TablePerformanceDto>> GetTablePerformanceAsync(
        ReportPeriod period,
        CancellationToken ct)
    {
        var sessions = await _db.TableSessions
            .AsNoTracking()
            .Where(s => s.StartedAt >= period.From && s.StartedAt < period.To)
            .Select(s => new
            {
                s.Id,
                s.TableId,
                s.GuestCount,
                s.StartedAt,
                s.EndedAt,
                Waiters = s.Assignments.Select(a => a.WaiterId).Distinct().ToList(),
            })
            .ToListAsync(ct);

        var revenueBySession = await RevenueOrders(period)
            .GroupBy(o => o.TableSessionId)
            .Select(g => new { SessionId = g.Key, Revenue = g.Sum(o => o.Total.Amount), Count = g.Count() })
            .ToDictionaryAsync(x => x.SessionId, x => x, ct);

        var tables = await _db.RestaurantTables
            .AsNoTracking()
            .Select(t => new
            {
                t.Id,
                t.Number,
                ZoneName = _db.TableZones.Where(z => z.Id == t.ZoneId).Select(z => z.Name).FirstOrDefault(),
            })
            .ToListAsync(ct);

        var waiterNames = await _db.StaffProfiles
            .AsNoTracking()
            .ToDictionaryAsync(s => s.Id, s => s.DisplayName, ct);

        var now = _clock.UtcNow;

        return tables.Select(table =>
        {
            var mine = sessions.Where(s => s.TableId == table.Id).ToList();

            var revenue = mine.Sum(s => revenueBySession.GetValueOrDefault(s.Id)?.Revenue ?? 0m);
            var orderCount = mine.Sum(s => revenueBySession.GetValueOrDefault(s.Id)?.Count ?? 0);

            var minutes = mine
                .Select(s => ((s.EndedAt ?? now) - s.StartedAt).TotalMinutes)
                .DefaultIfEmpty(0)
                .Average();

            return new TablePerformanceDto(
                table.Id,
                table.Number,
                table.ZoneName,
                mine.Count,
                mine.Sum(s => s.GuestCount),
                orderCount,
                revenue,
                orderCount == 0 ? 0m : decimal.Round(revenue / orderCount, 2),
                (int)minutes,
                mine.SelectMany(s => s.Waiters)
                    .Distinct()
                    .Select(id => waiterNames.GetValueOrDefault(id, "?"))
                    .ToList());
        })
        .Where(t => t.SessionCount > 0)
        .OrderByDescending(t => t.Revenue)
        .ToList();
    }

    public async Task<IReadOnlyList<ProductPerformanceDto>> GetProductPerformanceAsync(
        ReportPeriod period,
        int top,
        CancellationToken ct)
    {
        var orderIds = RevenueOrders(period).Select(o => o.Id);

        return await _db.OrderItems
            .Where(i => orderIds.Contains(i.OrderId) && i.Status != OrderItemStatus.Cancelled)
            .GroupBy(i => new { i.ProductId, i.ProductNameSnapshot, i.StationCodeSnapshot })
            .Select(g => new ProductPerformanceDto(
                g.Key.ProductId,
                g.Key.ProductNameSnapshot,
                _db.Products
                    .Where(p => p.Id == g.Key.ProductId)
                    .Select(p => _db.MenuCategories
                        .Where(c => c.Id == p.CategoryId)
                        .Select(c => c.Name)
                        .FirstOrDefault())
                    .FirstOrDefault(),
                g.Key.StationCodeSnapshot,
                g.Sum(i => i.Quantity),
                g.Sum(i => i.LineTotal.Amount),
                g.Select(i => i.OrderId).Distinct().Count()))
            .OrderByDescending(p => p.QuantitySold)
            .Take(top < 1 ? 20 : top)
            .ToListAsync(ct);
    }

    /// <inheritdoc />
    public async Task<IReadOnlyList<StationPerformanceDto>> GetStationPerformanceAsync(
        ReportPeriod period,
        CancellationToken ct)
    {
        var stations = await _db.Stations
            .AsNoTracking()
            .Select(s => new { s.Id, s.Code })
            .ToListAsync(ct);

        var thresholds = await _db.Restaurants
            .AsNoTracking()
            .Include(r => r.Settings)
            .Select(r => new
            {
                r.Settings.KdsLateThresholdMinutes,
                r.Settings.BarLateThresholdMinutes,
            })
            .FirstOrDefaultAsync(ct);

        // Durations are pulled back to compute a median, which SQL Server cannot
        // express portably. Bounded by the period, so this stays a page of rows,
        // not a year of them.
        var completed = await _db.PreparationTickets
            .AsNoTracking()
            .Where(t => t.ReadyAt != null
                        && t.CreatedAtUtc >= period.From && t.CreatedAtUtc < period.To)
            .Select(t => new { t.StationId, t.CreatedAtUtc, ReadyAt = t.ReadyAt!.Value })
            .ToListAsync(ct);

        return stations.Select(station =>
        {
            var mine = completed.Where(t => t.StationId == station.Id).ToList();

            if (mine.Count == 0)
            {
                return new StationPerformanceDto(station.Id, station.Code, 0, 0, 0, 0, 0);
            }

            var seconds = mine
                .Select(t => (t.ReadyAt - t.CreatedAtUtc).TotalSeconds)
                .OrderBy(s => s)
                .ToList();

            var lateThreshold = (station.Code == StationCodes.Bar
                ? thresholds?.BarLateThresholdMinutes
                : thresholds?.KdsLateThresholdMinutes) ?? 15;

            var late = seconds.Count(s => s > lateThreshold * 60);

            return new StationPerformanceDto(
                station.Id,
                station.Code,
                seconds.Count,
                (int)seconds.Average(),
                (int)seconds[seconds.Count / 2],
                late,
                Math.Round(late * 100.0 / seconds.Count, 1));
        }).ToList();
    }

    public async Task<IReadOnlyList<CancellationDto>> GetCancellationsAsync(
        ReportPeriod period,
        CancellationToken ct)
        => await _db.Orders
            .AsNoTracking()
            .Where(o => o.Status == OrderStatus.Cancelled
                        && o.CancelledAt >= period.From && o.CancelledAt < period.To)
            .OrderByDescending(o => o.CancelledAt)
            .Select(o => new CancellationDto(
                o.Id,
                o.OrderNumber.Value,
                _db.RestaurantTables.Where(t => t.Id == o.TableId).Select(t => t.Number).FirstOrDefault() ?? "?",
                _db.StaffProfiles.Where(s => s.Id == o.WaiterId).Select(s => s.DisplayName).FirstOrDefault(),
                _db.StaffProfiles.Where(s => s.Id == o.CancelledBy).Select(s => s.DisplayName).FirstOrDefault(),
                o.Total.Amount,
                o.CancellationReason,
                o.CancelledAt!.Value))
            .ToListAsync(ct);

    public async Task<IReadOnlyList<PaymentReportRowDto>> GetPaymentsAsync(
        ReportPeriod period,
        CancellationToken ct)
        => await _db.Payments
            .AsNoTracking()
            .Where(p => p.CreatedAt >= period.From && p.CreatedAt < period.To)
            .OrderByDescending(p => p.CreatedAt)
            .Select(p => new PaymentReportRowDto(
                p.Id,
                _db.Orders.Where(o => o.Id == p.OrderId).Select(o => o.OrderNumber.Value).FirstOrDefault() ?? "?",
                _db.Orders
                    .Where(o => o.Id == p.OrderId)
                    .Select(o => _db.RestaurantTables
                        .Where(t => t.Id == o.TableId).Select(t => t.Number).FirstOrDefault())
                    .FirstOrDefault() ?? "?",
                p.Amount.Amount,
                p.Method,
                p.Status,
                _db.StaffProfiles.Where(s => s.Id == p.ProcessedBy).Select(s => s.DisplayName).FirstOrDefault(),
                p.PaidAt))
            .ToListAsync(ct);

    public async Task<AdminDashboardDto> GetDashboardAsync(CancellationToken ct)
    {
        var now = _clock.UtcNow;
        var period = ReportPeriod.Today(now);
        var orders = RevenueOrders(period);

        var revenue = await orders.SumAsync(o => (decimal?)o.Total.Amount, ct) ?? 0m;
        var orderCount = await orders.CountAsync(ct);

        var byHour = await orders
            .GroupBy(o => o.CreatedAt.Hour)
            .Select(g => new { Hour = g.Key, Revenue = g.Sum(o => o.Total.Amount), Count = g.Count() })
            .OrderBy(x => x.Hour)
            .ToListAsync(ct);

        var tableStates = await _db.RestaurantTables
            .Where(t => t.IsActive)
            .GroupBy(t => t.Status)
            .Select(g => new { Status = g.Key, Count = g.Count() })
            .ToListAsync(ct);

        var openTickets = await _db.PreparationTickets
            .Where(t => t.Status == PreparationTicketStatus.New
                        || t.Status == PreparationTicketStatus.Accepted
                        || t.Status == PreparationTicketStatus.InPreparation)
            .Select(t => new
            {
                t.StationId,
                t.CreatedAtUtc,
                Code = _db.Stations.Where(s => s.Id == t.StationId).Select(s => s.Code).FirstOrDefault(),
            })
            .ToListAsync(ct);

        var lateThreshold = await _db.Restaurants
            .Include(r => r.Settings)
            .Select(r => (int?)r.Settings.KdsLateThresholdMinutes)
            .FirstOrDefaultAsync(ct) ?? 15;

        return new AdminDashboardDto
        {
            Currency = await CurrencyAsync(ct),
            RevenueToday = revenue,
            OrdersToday = orderCount,
            AverageTicketToday = orderCount == 0 ? 0m : decimal.Round(revenue / orderCount, 2),
            GuestsToday = await _db.TableSessions
                .Where(s => s.StartedAt >= period.From && s.StartedAt < period.To)
                .SumAsync(s => (int?)s.GuestCount, ct) ?? 0,
            TablesOccupied = tableStates.FirstOrDefault(t => t.Status == TableStatus.Occupied)?.Count ?? 0,
            TablesAvailable = tableStates.FirstOrDefault(t => t.Status == TableStatus.Available)?.Count ?? 0,
            OrdersInKitchen = openTickets.Count(t => t.Code == StationCodes.Kitchen),
            OrdersAtBar = openTickets.Count(t => t.Code == StationCodes.Bar),
            OrdersReady = await _db.Orders.CountAsync(o => o.Status == OrderStatus.Ready, ct),
            OrdersServedToday = await orders.CountAsync(o => o.ServedAt != null, ct),
            LateTickets = openTickets.Count(t => (now - t.CreatedAtUtc).TotalMinutes > lateThreshold),
            ActiveWaiters = await _db.ServiceAssignments
                .Where(a => a.Status == ServiceAssignmentStatus.Active)
                .Select(a => a.WaiterId)
                .Distinct()
                .CountAsync(ct),
            RevenueByHour = byHour
                .Select(h => new SalesPointDto(period.From.AddHours(h.Hour), h.Revenue, h.Count, 0))
                .ToList(),
            TopProducts = await GetProductPerformanceAsync(period, 5, ct),
        };
    }

    /// <summary>
    /// Orders that count as revenue: everything confirmed and not cancelled.
    /// Cancelled orders are excluded here rather than subtracted later, so no
    /// report can forget to.
    /// </summary>
    private IQueryable<Order> RevenueOrders(ReportPeriod period)
        => _db.Orders
            .AsNoTracking()
            .Where(o => o.CreatedAt >= period.From
                        && o.CreatedAt < period.To
                        && o.Status != OrderStatus.Cancelled
                        && o.Status != OrderStatus.Draft);

    private async Task<string> CurrencyAsync(CancellationToken ct)
        => await _db.Restaurants.AsNoTracking().Select(r => r.Currency).FirstOrDefaultAsync(ct) ?? "HTG";
}
