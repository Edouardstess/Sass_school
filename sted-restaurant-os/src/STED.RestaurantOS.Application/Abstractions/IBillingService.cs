using STED.RestaurantOS.Application.Billing.Dtos;
using STED.RestaurantOS.Application.Common;

namespace STED.RestaurantOS.Application.Abstractions;

public interface IBillingService
{
    /// <summary>Tables waiting to pay, oldest first.</summary>
    Task<IReadOnlyList<PendingSessionDto>> GetPendingSessionsAsync(CancellationToken ct);

    Task<Result<BillDto>> GetBillAsync(Guid tableSessionId, CancellationToken ct);

    /// <summary>
    /// Takes money.
    /// <para>
    /// Guarded three ways: the idempotency record (written in the same
    /// transaction), a unique index on (restaurant, key), and a check that the
    /// running total never passes what is owed. Charging a guest twice is the
    /// worst thing this system could do, so it is defended more than once.
    /// </para>
    /// </summary>
    Task<Result<PaymentDto>> TakePaymentAsync(
        TakePaymentRequest request,
        string idempotencyKey,
        CancellationToken ct);

    Task<Result<ReceiptDto>> GetReceiptAsync(Guid paymentId, CancellationToken ct);

    Task<Result<PaymentDto>> RefundAsync(Guid paymentId, RefundRequest request, CancellationToken ct);
}
