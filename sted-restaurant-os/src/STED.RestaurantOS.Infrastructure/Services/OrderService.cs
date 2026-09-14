using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Ordering.Dtos;
using STED.RestaurantOS.Domain.Catalog;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.Preparation;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Infrastructure.Persistence;
using STED.RestaurantOS.Shared.Errors;
using STED.RestaurantOS.Shared.Paging;

namespace STED.RestaurantOS.Infrastructure.Services;

public sealed partial class OrderService : IOrderService
{
    private readonly AppDbContext _db;
    private readonly IUnitOfWork _uow;
    private readonly ITenantContext _tenant;
    private readonly IDateTimeProvider _clock;
    private readonly IRealtimeNotifier _realtime;
    private readonly NumberSequence _numbers;
    private readonly ILogger<OrderService> _logger;

    public OrderService(
        AppDbContext db,
        IUnitOfWork uow,
        ITenantContext tenant,
        IDateTimeProvider clock,
        IRealtimeNotifier realtime,
        NumberSequence numbers,
        ILogger<OrderService> logger)
    {
        _db = db;
        _uow = uow;
        _tenant = tenant;
        _clock = clock;
        _realtime = realtime;
        _numbers = numbers;
        _logger = logger;
    }

    public async Task<Result<CartQuoteDto>> QuoteAsync(
        IReadOnlyList<CartLineRequest> lines,
        CancellationToken ct)
    {
        if (_tenant.RestaurantId is not { } restaurantId)
        {
            return Error.Unauthorized("No restaurant in context.");
        }

        var settings = await LoadSettingsAsync(restaurantId, ct);

        if (settings is null)
        {
            return Error.NotFound("Restaurant not found.");
        }

        var catalogue = await LoadCatalogueAsync(lines, ct);
        var quoteLines = new List<CartQuoteLineDto>();
        var unavailable = new List<UnavailableLineDto>();

        decimal subtotal = 0m, tax = 0m;

        foreach (var line in lines)
        {
            if (!catalogue.TryGetValue(line.ProductId, out var product))
            {
                unavailable.Add(new UnavailableLineDto(line.ProductId, "Unknown product", "This product no longer exists."));
                continue;
            }

            if (!product.IsOrderable)
            {
                unavailable.Add(new UnavailableLineDto(product.Id, product.Name, "Currently unavailable."));
                continue;
            }

            var selected = product.Modifiers
                .SelectMany(m => m.Options)
                .Where(o => line.ModifierOptionIds.Contains(o.Id))
                .ToList();

            var modifiersUnit = selected.Sum(o => o.PriceDelta.Amount);
            var unitPrice = product.Price.Amount + modifiersUnit;
            var lineSubtotal = decimal.Round(unitPrice * line.Quantity, 2, MidpointRounding.AwayFromZero);

            var lineTax = settings.TaxIncludedInPrice
                ? lineSubtotal - decimal.Round(lineSubtotal / (1m + product.TaxRate.Value), 2, MidpointRounding.AwayFromZero)
                : decimal.Round(lineSubtotal * product.TaxRate.Value, 2, MidpointRounding.AwayFromZero);

            subtotal += lineSubtotal;
            tax += lineTax;

            quoteLines.Add(new CartQuoteLineDto(
                product.Id,
                product.Name,
                line.Quantity,
                product.Price.Amount,
                modifiersUnit,
                lineSubtotal,
                lineTax,
                settings.TaxIncludedInPrice ? lineSubtotal : lineSubtotal + lineTax,
                selected.Select(o => o.Name).ToList()));
        }

        var serviceCharge = decimal.Round(
            subtotal * (settings.ServiceChargeRate.Value / 100m), 2, MidpointRounding.AwayFromZero);

        var total = settings.TaxIncludedInPrice
            ? subtotal + serviceCharge
            : subtotal + tax + serviceCharge;

        return new CartQuoteDto
        {
            Subtotal = subtotal,
            TaxAmount = tax,
            ServiceChargeAmount = serviceCharge,
            Total = total,
            Currency = settings.Currency,
            Lines = quoteLines,
            Unavailable = unavailable,
        };
    }

    /// <inheritdoc />
    public async Task<Result<OrderDto>> PlaceOrderAsync(
        Guid tableSessionId,
        PlaceOrderRequest request,
        OrderSource source,
        CancellationToken ct)
    {
        if (_tenant.RestaurantId is not { } restaurantId)
        {
            return Error.Unauthorized("No restaurant in context.");
        }

        if (request.Lines.Count == 0)
        {
            return Error.Conflict(ErrorCodes.OrderEmpty, "An order must contain at least one line.");
        }

        var settings = await LoadSettingsAsync(restaurantId, ct);

        if (settings is null)
        {
            return Error.NotFound("Restaurant not found.");
        }

        return await _uow.ExecuteInTransactionAsync<Result<OrderDto>>(async token =>
        {
            // 1. The session must exist and still be open. Everything else hangs off it.
            var session = await _db.TableSessions
                .Include(s => s.Assignments)
                .FirstOrDefaultAsync(s => s.Id == tableSessionId, token);

            if (session is null)
            {
                return Error.NotFound("This table session does not exist.");
            }

            if (!session.IsOpen)
            {
                return Error.Conflict(ErrorCodes.SessionClosed, "This table session is closed.");
            }

            // 2. One query for every product involved. Never one per line.
            var catalogue = await LoadCatalogueAsync(request.Lines, token);

            // 3. Re-check availability inside the transaction: a product may
            //    have sold out while the guest was choosing.
            foreach (var line in request.Lines)
            {
                if (!catalogue.TryGetValue(line.ProductId, out var product))
                {
                    return Error.Conflict(ErrorCodes.ProductUnavailable, "One of the products no longer exists.");
                }

                product.EnsureOrderable();

                var selectionsByModifier = product.Modifiers.ToDictionary(
                    m => m.Id,
                    m => (IReadOnlyCollection<Guid>)m.Options
                        .Where(o => line.ModifierOptionIds.Contains(o.Id))
                        .Select(o => o.Id)
                        .ToList());

                product.EnsureModifierSelectionIsValid(selectionsByModifier);
            }

            var now = _clock.UtcNow;
            var orderNumber = await _numbers.NextOrderNumberAsync(restaurantId, now, token);

            var order = Order.Create(
                restaurantId,
                session.TableId,
                session.Id,
                orderNumber,
                source,
                settings.Currency,
                settings.TaxIncludedInPrice,
                settings.ServiceChargeRate,
                now,
                _tenant.StaffProfileId,
                request.Notes);

            // 4. Snapshot everything. From here the catalogue can change freely.
            foreach (var line in request.Lines)
            {
                var product = catalogue[line.ProductId];

                var modifiers = product.Modifiers
                    .SelectMany(m => m.Options.Select(o => new { Modifier = m, Option = o }))
                    .Where(x => line.ModifierOptionIds.Contains(x.Option.Id))
                    .Select(x => new ModifierSelectionSnapshot(
                        x.Option.Id,
                        x.Modifier.Name,
                        x.Option.Name,
                        x.Option.PriceDelta))
                    .ToList();

                order.AddItem(
                    new ProductSnapshot(
                        product.Id,
                        product.Name,
                        product.Price,
                        product.TaxRate,
                        product.StationId,
                        await StationCodeAsync(product.StationId, token)),
                    line.Quantity,
                    line.Notes,
                    modifiers);
            }

            // 5. The responsible waiter is read from the session's active
            //    assignment — never from the QR code, never from the client.
            //    Null is a valid answer: nobody has taken this table yet.
            var waiterId = session.CurrentWaiterId;

            if (settings.OrderRequiresWaiterConfirmation && source == OrderSource.Qr)
            {
                // The venue wants a human to vet guest orders before the kitchen
                // sees them, so it stops at PENDING.
                order.Submit(now);
                _db.Orders.Add(order);
            }
            else
            {
                order.Confirm(waiterId, now);
                _db.Orders.Add(order);

                // 6. One ticket per distinct station, each holding only its own lines.
                foreach (var ticket in BuildTickets(order, now))
                {
                    _db.PreparationTickets.Add(ticket);
                }
            }

            session.MarkActive();

            // 7. Commit, and only then tell the screens.
            return await FinishAsync(order, session, token);
        }, ct);
    }

    private IEnumerable<PreparationTicket> BuildTickets(Order order, DateTimeOffset now)
    {
        foreach (var stationId in order.StationIds)
        {
            var lines = order.Items
                .Where(i => i.StationId == stationId && i.Status != OrderItemStatus.Cancelled)
                .ToList();

            if (lines.Count == 0)
            {
                continue;
            }

            var ticket = PreparationTicket.Create(
                order.RestaurantId,
                order.Id,
                stationId,
                NumberSequence.TicketNumber(order.OrderNumber, lines[0].StationCodeSnapshot),
                now);

            foreach (var line in lines)
            {
                ticket.AddItem(
                    line.Id,
                    line.ProductNameSnapshot,
                    line.Quantity,
                    line.Notes,
                    line.Modifiers.Count == 0
                        ? null
                        : string.Join(", ", line.Modifiers.Select(m => m.OptionNameSnapshot)));
            }

            ticket.PublishCreated(now);
            yield return ticket;
        }
    }

    private async Task<Result<OrderDto>> FinishAsync(Order order, TableSession session, CancellationToken ct)
    {
        var events = await _uow.CommitTransactionAsync(ct);

        _logger.LogInformation(
            "Order {OrderNumber} placed on table session {SessionId} with {ItemCount} item(s), waiter {WaiterId}.",
            order.OrderNumber.Value,
            session.Id,
            order.ItemCount,
            order.WaiterId);

        await PublishAsync(events, ct);

        var dto = await ProjectAsync(order.Id, ct);

        return dto is null
            ? Error.NotFound("The order could not be read back.")
            : dto;
    }

    private async Task<OrderingSettings?> LoadSettingsAsync(Guid restaurantId, CancellationToken ct)
    {
        var restaurant = await _db.Restaurants
            .Include(r => r.Settings)
            .FirstOrDefaultAsync(r => r.Id == restaurantId, ct);

        if (restaurant is null)
        {
            return null;
        }

        return new OrderingSettings(
            restaurant.Currency,
            restaurant.Settings.TaxIncludedInPrice,
            restaurant.Settings.ServiceChargeRate,
            restaurant.Settings.MaxDiscountPercentage,
            restaurant.Settings.OrderRequiresWaiterConfirmation);
    }

    private async Task<Dictionary<Guid, Product>> LoadCatalogueAsync(
        IReadOnlyList<CartLineRequest> lines,
        CancellationToken ct)
    {
        var ids = lines.Select(l => l.ProductId).Distinct().ToList();

        return await _db.Products
            .Include(p => p.Modifiers)
            .ThenInclude(m => m.Options)
            .Where(p => ids.Contains(p.Id))
            .ToDictionaryAsync(p => p.Id, ct);
    }

    private readonly Dictionary<Guid, string> _stationCodes = [];

    private async Task<string> StationCodeAsync(Guid stationId, CancellationToken ct)
    {
        if (_stationCodes.TryGetValue(stationId, out var cached))
        {
            return cached;
        }

        var code = await _db.Stations
            .Where(s => s.Id == stationId)
            .Select(s => s.Code)
            .FirstOrDefaultAsync(ct) ?? StationCodes.Kitchen;

        _stationCodes[stationId] = code;
        return code;
    }

    private sealed record OrderingSettings(
        string Currency,
        bool TaxIncludedInPrice,
        Percentage ServiceChargeRate,
        Percentage MaxDiscountPercentage,
        bool OrderRequiresWaiterConfirmation);
}
