using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Shared;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Domain.Billing;

/// <summary>
/// Money actually taken. Never deleted — a mistaken payment is refunded, which
/// leaves both facts in the record.
/// <para>
/// The idempotency key is stored on the row itself and covered by a unique index
/// per restaurant. That is the last line of defence against the failure that
/// matters most in this product: a cashier tapping twice on a bad connection and
/// charging a guest twice.
/// </para>
/// </summary>
public sealed class Payment : AuditableAggregateRoot, ITenantEntity
{
    private Payment()
    {
    }

    private Payment(
        Guid id,
        Guid restaurantId,
        Guid orderId,
        Guid tableSessionId,
        Money amount,
        PaymentMethod method,
        Guid processedBy,
        string idempotencyKey)
        : base(id)
    {
        RestaurantId = restaurantId;
        OrderId = orderId;
        TableSessionId = tableSessionId;
        Amount = amount;
        Currency = amount.Currency;
        Method = method;
        ProcessedBy = processedBy;
        IdempotencyKey = idempotencyKey;
        Status = PaymentStatus.Pending;
    }

    public Guid RestaurantId { get; private set; }

    public Guid OrderId { get; private set; }

    public Guid TableSessionId { get; private set; }

    public Money Amount { get; private set; } = null!;

    public string Currency { get; private set; } = null!;

    public PaymentMethod Method { get; private set; }

    public PaymentStatus Status { get; private set; }

    public string? TransactionReference { get; private set; }

    public string? ProviderPayloadJson { get; private set; }

    public DateTimeOffset? PaidAt { get; private set; }

    public Guid ProcessedBy { get; private set; }

    public string IdempotencyKey { get; private set; } = null!;

    public string? FailureReason { get; private set; }

    public DateTimeOffset? RefundedAt { get; private set; }

    public bool CountsTowardsSettlement => Status == PaymentStatus.Completed;

    public static Payment Create(
        Guid restaurantId,
        Guid orderId,
        Guid tableSessionId,
        Money amount,
        PaymentMethod method,
        Guid processedBy,
        string idempotencyKey)
    {
        Guard.NotEmpty(restaurantId);
        Guard.NotEmpty(orderId);
        Guard.NotEmpty(processedBy);
        Guard.NotNullOrWhiteSpace(idempotencyKey);
        Guard.NotNull(amount);

        if (amount.Amount <= 0m)
        {
            throw new DomainValidationException("A payment amount must be greater than zero.");
        }

        return new Payment(
            Guid.CreateVersion7(),
            restaurantId,
            orderId,
            tableSessionId,
            amount,
            method,
            processedBy,
            Guard.MaxLength(idempotencyKey, 80));
    }

    /// <summary>
    /// Guard applied before creating a payment: the running total of completed
    /// payments plus this one may never exceed what is owed (RG-070).
    /// </summary>
    public static void EnsureDoesNotExceedTotal(Money alreadyPaid, Money candidate, Money orderTotal)
    {
        if (alreadyPaid.Add(candidate) > orderTotal)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.PaymentExceedsTotal,
                "This payment would take the order past its total.");
        }
    }

    public void MarkCompleted(DateTimeOffset at, string? transactionReference = null, string? providerPayloadJson = null)
    {
        if (Status is PaymentStatus.Refunded or PaymentStatus.Cancelled)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.OrderImmutable,
                $"A {Status} payment cannot be completed.");
        }

        Status = PaymentStatus.Completed;
        PaidAt = at;
        TransactionReference = transactionReference;
        ProviderPayloadJson = providerPayloadJson;
        FailureReason = null;
    }

    public void MarkFailed(string reason)
    {
        Status = PaymentStatus.Failed;
        FailureReason = Guard.MaxLength(reason, 300);
    }

    public void Cancel() => Status = PaymentStatus.Cancelled;

    public void Refund(DateTimeOffset at, string? reason = null)
    {
        if (Status != PaymentStatus.Completed)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.OrderImmutable,
                "Only a completed payment can be refunded.");
        }

        Status = PaymentStatus.Refunded;
        RefundedAt = at;
        FailureReason = reason is null ? null : Guard.MaxLength(reason, 300);
    }
}
