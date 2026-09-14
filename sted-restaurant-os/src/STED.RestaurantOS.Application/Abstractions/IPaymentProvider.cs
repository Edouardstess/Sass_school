using STED.RestaurantOS.Domain.Billing;
using STED.RestaurantOS.Domain.ValueObjects;

namespace STED.RestaurantOS.Application.Abstractions;

public sealed record PaymentRequest(
    Guid RestaurantId,
    Guid OrderId,
    Money Amount,
    string? TransactionReference,
    string IdempotencyKey);

public sealed record PaymentOutcome(
    bool Succeeded,
    PaymentStatus Status,
    string? TransactionReference,
    string? ProviderPayloadJson,
    string? FailureReason)
{
    public static PaymentOutcome Completed(string? reference = null, string? payload = null)
        => new(true, PaymentStatus.Completed, reference, payload, null);

    public static PaymentOutcome Pending(string reference, string? payload = null)
        => new(true, PaymentStatus.Pending, reference, payload, null);

    public static PaymentOutcome Failed(string reason)
        => new(false, PaymentStatus.Failed, null, null, reason);
}

/// <summary>
/// One payment method.
/// <para>
/// Only cash exists today, and it is implemented honestly: handing over notes
/// completes immediately and touches no network. The interface is shaped for the
/// mobile-money providers that follow (MonCash, NatCash, card), which complete
/// asynchronously via <see cref="ConfirmAsync"/> after a webhook — adding one
/// means writing an implementation, not migrating the database.
/// </para>
/// <para>
/// Nothing here simulates a real payment.
/// </para>
/// </summary>
public interface IPaymentProvider
{
    PaymentMethod Method { get; }

    Task<PaymentOutcome> InitiateAsync(PaymentRequest request, CancellationToken ct);

    Task<PaymentOutcome> ConfirmAsync(string transactionReference, CancellationToken ct);

    Task<PaymentOutcome> RefundAsync(string transactionReference, Money amount, CancellationToken ct);
}

public interface IPaymentProviderResolver
{
    IPaymentProvider Resolve(PaymentMethod method);
}
