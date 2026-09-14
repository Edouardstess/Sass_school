using Microsoft.EntityFrameworkCore;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Infrastructure.Persistence;

namespace STED.RestaurantOS.Infrastructure.Services;

/// <summary>
/// Produces the numbers humans use: order numbers, session numbers, ticket
/// numbers. Sequential per restaurant, and — for orders — per service day.
/// <para>
/// Derived by counting what already exists rather than from a database sequence,
/// because the number has to restart daily and be scoped per venue. Two
/// simultaneous orders can compute the same value; the unique index on
/// (RestaurantId, OrderNumber) catches it and the caller retries. That is a
/// deliberate trade: a collision costs one retry at peak, whereas a shared
/// sequence would cost a hot row on every single order.
/// </para>
/// </summary>
public sealed class NumberSequence
{
    private readonly AppDbContext _db;

    public NumberSequence(AppDbContext db) => _db = db;

    public async Task<OrderNumber> NextOrderNumberAsync(
        Guid restaurantId,
        DateTimeOffset serviceDay,
        CancellationToken ct)
    {
        var dayStart = new DateTimeOffset(serviceDay.Date, serviceDay.Offset);
        var dayEnd = dayStart.AddDays(1);

        var todayCount = await _db.Orders
            .IgnoreQueryFilters()
            .CountAsync(
                o => o.RestaurantId == restaurantId && o.CreatedAt >= dayStart && o.CreatedAt < dayEnd,
                ct);

        return OrderNumber.Create(serviceDay, todayCount + 1);
    }

    public async Task<int> NextSessionNumberAsync(Guid restaurantId, CancellationToken ct)
    {
        var last = await _db.TableSessions
            .IgnoreQueryFilters()
            .Where(s => s.RestaurantId == restaurantId)
            .MaxAsync(s => (int?)s.SessionNumber, ct);

        return (last ?? 0) + 1;
    }

    public static string TicketNumber(OrderNumber orderNumber, string stationCode)
        => $"{orderNumber.Value}-{stationCode[..Math.Min(3, stationCode.Length)]}";
}
