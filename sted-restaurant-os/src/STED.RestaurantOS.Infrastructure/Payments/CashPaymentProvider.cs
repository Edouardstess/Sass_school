using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Domain.Billing;
using STED.RestaurantOS.Domain.ValueObjects;

namespace STED.RestaurantOS.Infrastructure.Payments;

/// <summary>
/// Cash. The guest hands over notes; there is no third party to ask and nothing
/// to wait for, so the payment completes on the spot.
/// <para>
/// This provider exists to give cash the same shape as the mobile-money ones
/// that follow, not to pretend anything happened over a network.
/// </para>
/// </summary>
public sealed class CashPaymentProvider : IPaymentProvider
{
    public PaymentMethod Method => PaymentMethod.Cash;

    public Task<PaymentOutcome> InitiateAsync(PaymentRequest request, CancellationToken ct)
    {
        _ = ct;

        // The reference is for the paper trail, not a provider transaction id.
        return Task.FromResult(PaymentOutcome.Completed($"CASH-{request.IdempotencyKey}"));
    }

    public Task<PaymentOutcome> ConfirmAsync(string transactionReference, CancellationToken ct)
    {
        _ = ct;
        return Task.FromResult(PaymentOutcome.Completed(transactionReference));
    }

    public Task<PaymentOutcome> RefundAsync(string transactionReference, Money amount, CancellationToken ct)
    {
        _ = ct;
        _ = amount;

        // Money out of the drawer. Recorded, not transacted.
        return Task.FromResult(PaymentOutcome.Completed(transactionReference));
    }
}

/// <summary>
/// Picks the provider for a method.
/// <para>
/// Asking for a method nobody implements fails loudly here rather than silently
/// recording a payment that never happened.
/// </para>
/// </summary>
public sealed class PaymentProviderResolver : IPaymentProviderResolver
{
    private readonly IReadOnlyDictionary<PaymentMethod, IPaymentProvider> _providers;

    public PaymentProviderResolver(IEnumerable<IPaymentProvider> providers)
        => _providers = providers.ToDictionary(p => p.Method);

    public IPaymentProvider Resolve(PaymentMethod method)
        => _providers.TryGetValue(method, out var provider)
            ? provider
            : throw new NotSupportedException(
                $"{method} is not available yet. Only {string.Join(", ", _providers.Keys)} are implemented.");
}
