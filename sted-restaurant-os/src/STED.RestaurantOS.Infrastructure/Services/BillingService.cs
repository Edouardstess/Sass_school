using Microsoft.Data.SqlClient;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Billing.Dtos;
using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Security;
using STED.RestaurantOS.Domain.Billing;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Infrastructure.Persistence;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Infrastructure.Services;

public sealed class BillingService : IBillingService
{
    private const int UniqueIndexViolation = 2601;
    private const int UniqueConstraintViolation = 2627;

    private readonly AppDbContext _db;
    private readonly IUnitOfWork _uow;
    private readonly ITenantContext _tenant;
    private readonly IDateTimeProvider _clock;
    private readonly IRealtimeNotifier _realtime;
    private readonly IPaymentProviderResolver _providers;
    private readonly ILogger<BillingService> _logger;

    public BillingService(
        AppDbContext db,
        IUnitOfWork uow,
        ITenantContext tenant,
        IDateTimeProvider clock,
        IRealtimeNotifier realtime,
        IPaymentProviderResolver providers,
        ILogger<BillingService> logger)
    {
        _db = db;
        _uow = uow;
        _tenant = tenant;
        _clock = clock;
        _realtime = realtime;
        _providers = providers;
        _logger = logger;
    }

    public async Task<IReadOnlyList<PendingSessionDto>> GetPendingSessionsAsync(CancellationToken ct)
        => await _db.TableSessions
            .AsNoTracking()
            .Where(s => s.Status == TableSessionStatus.Open || s.Status == TableSessionStatus.Active)
            .Select(s => new
            {
                s.Id,
                s.TableId,
                s.GuestCount,
                s.StartedAt,
                TableNumber = _db.RestaurantTables
                    .Where(t => t.Id == s.TableId).Select(t => t.Number).FirstOrDefault(),
                WaiterName = _db.StaffProfiles
                    .Where(p => p.Id == s.Assignments
                        .Where(a => a.Status == ServiceAssignmentStatus.Active)
                        .Select(a => a.WaiterId)
                        .FirstOrDefault())
                    .Select(p => p.DisplayName)
                    .FirstOrDefault(),
                OrderCount = _db.Orders.Count(o => o.TableSessionId == s.Id && o.Status != OrderStatus.Cancelled),
                Total = _db.Orders
                    .Where(o => o.TableSessionId == s.Id && o.Status != OrderStatus.Cancelled)
                    .Sum(o => (decimal?)o.Total.Amount) ?? 0m,
                Paid = _db.Payments
                    .Where(p => p.TableSessionId == s.Id && p.Status == PaymentStatus.Completed)
                    .Sum(p => (decimal?)p.Amount.Amount) ?? 0m,

                // Something to pay means at least one order has been served.
                Servable = _db.Orders.Any(o => o.TableSessionId == s.Id && o.Status == OrderStatus.Served),
            })
            .Where(s => s.Total > s.Paid)
            .OrderBy(s => s.StartedAt)
            .Select(s => new PendingSessionDto(
                s.Id,
                s.TableId,
                s.TableNumber ?? "?",
                s.WaiterName,
                s.GuestCount,
                s.StartedAt,
                s.OrderCount,
                s.Total,
                s.Paid,
                s.Total - s.Paid,
                s.Servable))
            .ToListAsync(ct);

    public async Task<Result<BillDto>> GetBillAsync(Guid tableSessionId, CancellationToken ct)
    {
        var session = await _db.TableSessions
            .AsNoTracking()
            .Where(s => s.Id == tableSessionId)
            .Select(s => new
            {
                s.Id,
                s.TableId,
                s.GuestCount,
                s.StartedAt,
                TableNumber = _db.RestaurantTables
                    .Where(t => t.Id == s.TableId).Select(t => t.Number).FirstOrDefault(),
                WaiterId = s.Assignments
                    .Where(a => a.Status == ServiceAssignmentStatus.Active)
                    .Select(a => (Guid?)a.WaiterId)
                    .FirstOrDefault(),
            })
            .FirstOrDefaultAsync(ct);

        if (session is null)
        {
            return Error.NotFound("This session does not exist.");
        }

        var orders = await _db.Orders
            .AsNoTracking()
            .Include(o => o.Items)
            .Where(o => o.TableSessionId == tableSessionId && o.Status != OrderStatus.Cancelled)
            .OrderBy(o => o.CreatedAt)
            .ToListAsync(ct);

        var payments = await ProjectPaymentsAsync(
            _db.Payments.AsNoTracking().Where(p => p.TableSessionId == tableSessionId), ct);

        var waiterName = session.WaiterId is null
            ? null
            : await _db.StaffProfiles
                .Where(s => s.Id == session.WaiterId)
                .Select(s => s.DisplayName)
                .FirstOrDefaultAsync(ct);

        var currency = orders.FirstOrDefault()?.Currency
            ?? await _db.Restaurants.Select(r => r.Currency).FirstOrDefaultAsync(ct)
            ?? "HTG";

        return new BillDto
        {
            TableSessionId = session.Id,
            TableId = session.TableId,
            TableNumber = session.TableNumber ?? "?",
            GuestCount = session.GuestCount,
            WaiterName = waiterName,
            StartedAt = session.StartedAt,
            Currency = currency,
            Subtotal = orders.Sum(o => o.Subtotal.Amount),
            TaxAmount = orders.Sum(o => o.TaxAmount.Amount),
            DiscountAmount = orders.Sum(o => o.DiscountAmount.Amount),
            ServiceChargeAmount = orders.Sum(o => o.ServiceChargeAmount.Amount),
            Total = orders.Sum(o => o.Total.Amount),
            Paid = payments.Where(p => p.Status == PaymentStatus.Completed).Sum(p => p.Amount),
            Orders = orders.Select(o => new BillOrderDto(
                o.Id,
                o.OrderNumber.Value,
                o.Status,
                o.Total.Amount,
                o.CreatedAt,
                o.Items.Select(i => new BillLineDto(
                    i.ProductNameSnapshot,
                    i.Quantity,
                    i.EffectiveUnitPrice.Amount,
                    i.LineTotal.Amount)).ToList())).ToList(),
            Payments = payments,
        };
    }

    /// <inheritdoc />
    public async Task<Result<PaymentDto>> TakePaymentAsync(
        TakePaymentRequest request,
        string idempotencyKey,
        CancellationToken ct)
    {
        if (_tenant.RestaurantId is not { } restaurantId)
        {
            return Error.Unauthorized("No restaurant in context.");
        }

        if (_tenant.StaffProfileId is not { } cashierId)
        {
            return Error.Forbidden("Only a staff member can take a payment.");
        }

        if (request.Amount <= 0m)
        {
            return Error.Validation("A payment amount must be greater than zero.");
        }

        // Cheap pre-check. The real guarantee is the unique index below: two
        // simultaneous requests with one key must produce one payment, and only
        // the database can promise that.
        var existing = await _db.Payments
            .AsNoTracking()
            .FirstOrDefaultAsync(p => p.IdempotencyKey == idempotencyKey, ct);

        if (existing is not null)
        {
            _logger.LogInformation(
                "Idempotent replay of payment {PaymentId} for key {Key}.", existing.Id, idempotencyKey);

            return (await ProjectPaymentsAsync(
                _db.Payments.AsNoTracking().Where(p => p.Id == existing.Id), ct))[0];
        }

        try
        {
            return await _uow.ExecuteInTransactionAsync<Result<PaymentDto>>(async token =>
            {
                var order = await ResolveOrderAsync(request, token);

                if (order is null)
                {
                    return Error.Conflict(ErrorCodes.OrderNotFound, "Nothing left to pay on this table.");
                }

                if (order.Status is OrderStatus.Cancelled or OrderStatus.Closed)
                {
                    return Error.Conflict(
                        ErrorCodes.OrderImmutable, $"This order is already {order.Status}.");
                }

                var currency = order.Currency;
                var amount = Money.Of(request.Amount, currency);

                var alreadyPaid = Money.Of(
                    await _db.Payments
                        .Where(p => p.OrderId == order.Id && p.Status == PaymentStatus.Completed)
                        .SumAsync(p => (decimal?)p.Amount.Amount, token) ?? 0m,
                    currency);

                // Never take more than is owed.
                Payment.EnsureDoesNotExceedTotal(alreadyPaid, amount, order.Total);

                var payment = Payment.Create(
                    restaurantId, order.Id, order.TableSessionId, amount,
                    request.Method, cashierId, idempotencyKey);

                var provider = _providers.Resolve(request.Method);

                var outcome = await provider.InitiateAsync(
                    new PaymentRequest(restaurantId, order.Id, amount, request.TransactionReference, idempotencyKey),
                    token);

                if (!outcome.Succeeded)
                {
                    payment.MarkFailed(outcome.FailureReason ?? "The payment was declined.");
                    _db.Payments.Add(payment);
                    await _uow.CommitTransactionAsync(token);

                    return Error.Conflict(ErrorCodes.ValidationError, outcome.FailureReason ?? "Payment declined.");
                }

                if (outcome.Status == PaymentStatus.Completed)
                {
                    payment.MarkCompleted(_clock.UtcNow, outcome.TransactionReference, outcome.ProviderPayloadJson);
                }

                _db.Payments.Add(payment);

                // Settled in full closes the order, which is what lets the
                // session be closed and the table turned over.
                if (outcome.Status == PaymentStatus.Completed
                    && alreadyPaid.Add(amount) >= order.Total)
                {
                    order.Close(cashierId, _clock.UtcNow);
                }

                var events = await _uow.CommitTransactionAsync(token);

                await _realtime.NotifyRestaurantAsync(
                    restaurantId,
                    RealtimeEvents.PaymentCompleted,
                    new { payment.Id, order.Id, Amount = amount.Amount, request.Method },
                    token);

                _ = events;

                _logger.LogInformation(
                    "Payment {PaymentId} of {Amount} {Currency} taken on order {OrderNumber} by {CashierId}.",
                    payment.Id, amount.Amount, currency, order.OrderNumber.Value, cashierId);

                return (await ProjectPaymentsAsync(
                    _db.Payments.AsNoTracking().Where(p => p.Id == payment.Id), token))[0];
            }, ct);
        }
        catch (DbUpdateException exception) when (IsUniqueViolation(exception))
        {
            // Two requests with the same key raced. Exactly one row exists; the
            // other caller gets it back instead of a second charge.
            _logger.LogInformation("Concurrent payment with key {Key} settled by the unique index.", idempotencyKey);

            var winner = await _db.Payments
                .AsNoTracking()
                .FirstOrDefaultAsync(p => p.IdempotencyKey == idempotencyKey, ct);

            return winner is null
                ? Error.Conflict(ErrorCodes.IdempotencyKeyReused, "This payment could not be completed.")
                : (await ProjectPaymentsAsync(
                    _db.Payments.AsNoTracking().Where(p => p.Id == winner.Id), ct))[0];
        }
    }

    public async Task<Result<PaymentDto>> RefundAsync(
        Guid paymentId,
        RefundRequest request,
        CancellationToken ct)
    {
        return await _uow.ExecuteInTransactionAsync<Result<PaymentDto>>(async token =>
        {
            var payment = await _db.Payments.FirstOrDefaultAsync(p => p.Id == paymentId, token);

            if (payment is null)
            {
                return Error.NotFound("This payment does not exist.");
            }

            var provider = _providers.Resolve(payment.Method);

            var outcome = await provider.RefundAsync(
                payment.TransactionReference ?? string.Empty, payment.Amount, token);

            if (!outcome.Succeeded)
            {
                return Error.Conflict(
                    ErrorCodes.ValidationError, outcome.FailureReason ?? "The refund was declined.");
            }

            // Never deleted: the charge and the refund are both facts.
            payment.Refund(_clock.UtcNow, request.Reason);

            await _uow.CommitTransactionAsync(token);

            return (await ProjectPaymentsAsync(
                _db.Payments.AsNoTracking().Where(p => p.Id == paymentId), token))[0];
        }, ct);
    }

    public async Task<Result<ReceiptDto>> GetReceiptAsync(Guid paymentId, CancellationToken ct)
    {
        var payment = await _db.Payments
            .AsNoTracking()
            .FirstOrDefaultAsync(p => p.Id == paymentId, ct);

        if (payment is null)
        {
            return Error.NotFound("This payment does not exist.");
        }

        var order = await _db.Orders
            .AsNoTracking()
            .Include(o => o.Items)
            .FirstOrDefaultAsync(o => o.Id == payment.OrderId, ct);

        if (order is null)
        {
            return Error.NotFound("The order behind this payment no longer exists.");
        }

        var restaurant = await _db.Restaurants
            .AsNoTracking()
            .Include(r => r.Settings)
            .FirstOrDefaultAsync(r => r.Id == payment.RestaurantId, ct);

        var tableNumber = await _db.RestaurantTables
            .Where(t => t.Id == order.TableId).Select(t => t.Number).FirstOrDefaultAsync(ct);

        var waiterName = await _db.StaffProfiles
            .Where(s => s.Id == order.WaiterId).Select(s => s.DisplayName).FirstOrDefaultAsync(ct);

        var cashierName = await _db.StaffProfiles
            .Where(s => s.Id == payment.ProcessedBy).Select(s => s.DisplayName).FirstOrDefaultAsync(ct);

        return new ReceiptDto(
            payment.Id,
            restaurant?.Name ?? string.Empty,
            restaurant?.Address,
            restaurant?.Phone,
            restaurant?.Settings.ReceiptHeader,
            restaurant?.Settings.ReceiptFooter,
            tableNumber ?? "?",
            waiterName,
            cashierName ?? "?",
            payment.PaidAt ?? payment.CreatedAt,
            payment.Currency,
            order.Subtotal.Amount,
            order.TaxAmount.Amount,
            order.DiscountAmount.Amount,
            order.ServiceChargeAmount.Amount,
            order.Total.Amount,
            payment.Amount.Amount,
            payment.Method,
            order.Items.Select(i => new BillLineDto(
                i.ProductNameSnapshot,
                i.Quantity,
                i.EffectiveUnitPrice.Amount,
                i.LineTotal.Amount)).ToList());
    }

    /// <summary>
    /// Finds what to charge: the named order, or the oldest unsettled one on the
    /// table. Paying "the table" settles it order by order, oldest first.
    /// </summary>
    private async Task<Order?> ResolveOrderAsync(TakePaymentRequest request, CancellationToken ct)
    {
        if (request.OrderId is { } orderId)
        {
            return await _db.Orders.FirstOrDefaultAsync(o => o.Id == orderId, ct);
        }

        if (request.TableSessionId is not { } sessionId)
        {
            return null;
        }

        return await _db.Orders
            .Where(o => o.TableSessionId == sessionId
                        && o.Status != OrderStatus.Cancelled
                        && o.Status != OrderStatus.Closed)
            .OrderBy(o => o.CreatedAt)
            .FirstOrDefaultAsync(ct);
    }

    private async Task<IReadOnlyList<PaymentDto>> ProjectPaymentsAsync(
        IQueryable<Payment> query,
        CancellationToken ct)
        => await query
            .OrderBy(p => p.CreatedAt)
            .Select(p => new PaymentDto(
                p.Id,
                p.OrderId,
                p.Amount.Amount,
                p.Currency,
                p.Method,
                p.Status,
                p.PaidAt,
                p.ProcessedBy,
                _db.StaffProfiles.Where(s => s.Id == p.ProcessedBy)
                    .Select(s => s.DisplayName).FirstOrDefault(),
                p.TransactionReference))
            .ToListAsync(ct);

    private static bool IsUniqueViolation(DbUpdateException exception)
        => exception.InnerException is SqlException sql
           && sql.Number is UniqueIndexViolation or UniqueConstraintViolation;
}
