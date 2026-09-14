using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Ordering.Events;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Shared;
using STED.RestaurantOS.Shared.Errors;
using DomainExceptions = STED.RestaurantOS.Domain.Exceptions;

namespace STED.RestaurantOS.Domain.Ordering;

/// <summary>
/// What a group of guests asked for, and what it costs.
/// <para>
/// Two things about this aggregate are load-bearing for the whole product:
/// </para>
/// <list type="number">
/// <item>
/// Every monetary figure is computed here, from snapshots taken server-side.
/// Amounts arriving from a client are never read, only recomputed and compared.
/// </item>
/// <item>
/// <see cref="WaiterId"/> is set once, at confirmation, from the session's active
/// assignment — and then frozen. Transferring the table later moves future
/// orders to the new waiter and leaves this one exactly where it was.
/// </item>
/// </list>
/// </summary>
public sealed class Order : AuditableAggregateRoot, ITenantEntity, IHasRowVersion
{
    private readonly List<OrderItem> _items = [];
    private readonly List<OrderStatusHistory> _statusHistory = [];

    private Order()
    {
    }

    private Order(
        Guid id,
        Guid restaurantId,
        Guid tableId,
        Guid tableSessionId,
        OrderNumber orderNumber,
        OrderSource source,
        string currency,
        bool taxIncludedInPrice,
        Percentage serviceChargeRate,
        Guid? createdBy,
        DateTimeOffset createdAt)
        : base(id)
    {
        RestaurantId = restaurantId;
        TableId = tableId;
        TableSessionId = tableSessionId;
        OrderNumber = orderNumber;
        Source = source;
        Currency = currency;
        TaxIncludedInPrice = taxIncludedInPrice;
        ServiceChargeRate = serviceChargeRate;
        CreatedBy = createdBy;
        Status = OrderStatus.Draft;

        Subtotal = Money.Zero(currency);
        TaxAmount = Money.Zero(currency);
        DiscountAmount = Money.Zero(currency);
        ServiceChargeAmount = Money.Zero(currency);
        Total = Money.Zero(currency);

        _statusHistory.Add(OrderStatusHistory.Record(id, null, OrderStatus.Draft, createdBy, createdAt, "Order created"));
    }

    public Guid RestaurantId { get; private set; }

    public Guid TableId { get; private set; }

    public Guid TableSessionId { get; private set; }

    public OrderNumber OrderNumber { get; private set; } = null!;

    /// <summary>
    /// The waiter responsible for this order. Frozen at confirmation (RG-046).
    /// Null is legitimate: a guest can order at a table nobody has taken yet.
    /// </summary>
    public Guid? WaiterId { get; private set; }

    public OrderSource Source { get; private set; }

    public OrderStatus Status { get; private set; }

    public string Currency { get; private set; } = null!;

    public bool TaxIncludedInPrice { get; private set; }

    public Percentage ServiceChargeRate { get; private set; } = Percentage.Zero;

    public Money Subtotal { get; private set; } = null!;

    public Money TaxAmount { get; private set; } = null!;

    public Money DiscountAmount { get; private set; } = null!;

    public Money ServiceChargeAmount { get; private set; } = null!;

    public Money Total { get; private set; } = null!;

    public string? Notes { get; private set; }

    public string? DiscountReason { get; private set; }

    public DateTimeOffset? ConfirmedAt { get; private set; }

    public DateTimeOffset? StartedAt { get; private set; }

    public DateTimeOffset? ReadyAt { get; private set; }

    public DateTimeOffset? ServedAt { get; private set; }

    public DateTimeOffset? ClosedAt { get; private set; }

    public DateTimeOffset? CancelledAt { get; private set; }

    public Guid? CreatedBy { get; private set; }

    public Guid? ServedBy { get; private set; }

    public Guid? ClosedBy { get; private set; }

    public Guid? CancelledBy { get; private set; }

    public string? CancellationReason { get; private set; }

    public byte[]? RowVersion { get; private set; }

    public IReadOnlyCollection<OrderItem> Items => _items.AsReadOnly();

    public IReadOnlyCollection<OrderStatusHistory> StatusHistory => _statusHistory.AsReadOnly();

    public int ItemCount => _items.Sum(i => i.Quantity);

    public bool IsSettled => Status is OrderStatus.Closed or OrderStatus.Cancelled;

    public bool IsEditable => Status is OrderStatus.Draft or OrderStatus.Pending;

    /// <summary>Distinct stations involved: one preparation ticket per entry.</summary>
    public IReadOnlyCollection<Guid> StationIds
        => _items.Where(i => i.Status != OrderItemStatus.Cancelled)
            .Select(i => i.StationId)
            .Distinct()
            .ToList();

    public static Order Create(
        Guid restaurantId,
        Guid tableId,
        Guid tableSessionId,
        OrderNumber orderNumber,
        OrderSource source,
        string currency,
        bool taxIncludedInPrice,
        Percentage serviceChargeRate,
        DateTimeOffset createdAt,
        Guid? createdBy = null,
        string? notes = null)
    {
        Guard.NotEmpty(restaurantId);
        Guard.NotEmpty(tableId);
        Guard.NotEmpty(tableSessionId);
        Guard.NotNull(orderNumber);

        var order = new Order(
            Guid.CreateVersion7(),
            restaurantId,
            tableId,
            tableSessionId,
            orderNumber,
            source,
            currency,
            taxIncludedInPrice,
            serviceChargeRate,
            createdBy,
            createdAt);

        order.Notes = notes;
        return order;
    }

    public OrderItem AddItem(
        ProductSnapshot product,
        int quantity,
        string? notes = null,
        IReadOnlyCollection<ModifierSelectionSnapshot>? modifiers = null)
    {
        EnsureEditable();
        Guard.NotNull(product);

        if (!string.Equals(product.UnitPrice.Currency, Currency, StringComparison.Ordinal))
        {
            throw new DomainExceptions.BusinessRuleViolationException(
                ErrorCodes.CurrencyMismatch,
                "The product price is not in the order's currency.");
        }

        var item = OrderItem.Create(
            Id,
            product.ProductId,
            product.Name,
            product.UnitPrice,
            product.TaxRate,
            product.StationId,
            product.StationCode,
            quantity,
            notes);

        foreach (var modifier in modifiers ?? [])
        {
            item.AddModifier(
                modifier.ModifierOptionId,
                modifier.ModifierName,
                modifier.OptionName,
                modifier.PriceDelta);
        }

        _items.Add(item);
        Recalculate();
        return item;
    }

    public void RemoveItem(Guid orderItemId)
    {
        EnsureEditable();
        _items.RemoveAll(i => i.Id == orderItemId);
        Recalculate();
    }

    public void ChangeItemQuantity(Guid orderItemId, int quantity)
    {
        EnsureEditable();
        var item = FindItem(orderItemId);
        item.ChangeQuantity(quantity);
        Recalculate();
    }

    public void UpdateNotes(string? notes)
    {
        EnsureEditable();
        Notes = notes;
    }

    /// <summary>
    /// Guest submitted the order but the venue wants a waiter to vet it first
    /// (<c>RestaurantSettings.OrderRequiresWaiterConfirmation</c>).
    /// </summary>
    public void Submit(DateTimeOffset at)
    {
        EnsureHasItems();
        ChangeStatus(OrderStatus.Pending, CreatedBy, at, "Submitted by guest");
    }

    /// <summary>
    /// Accepts the order into the venue's workflow: this is the moment the
    /// responsible waiter is decided and frozen, and the moment the kitchen and
    /// bar tickets are created (by the in-transaction handler of the event).
    /// </summary>
    public void Confirm(Guid? waiterId, DateTimeOffset at, Guid? confirmedBy = null)
    {
        EnsureHasItems();

        if (WaiterId is null && waiterId is not null)
        {
            WaiterId = waiterId;
        }

        ChangeStatus(OrderStatus.Confirmed, confirmedBy ?? CreatedBy, at, "Order confirmed");
        ConfirmedAt = at;

        Raise(new OrderConfirmedDomainEvent(RestaurantId, Id, TableSessionId, at));
        Raise(new OrderCreatedDomainEvent(
            RestaurantId, Id, OrderNumber.Value, TableId, TableSessionId,
            WaiterId, Total.Amount, Currency, ItemCount, at));
    }

    public void MarkInPreparation(Guid? changedBy, DateTimeOffset at)
    {
        ChangeStatus(OrderStatus.InPreparation, changedBy, at, "Preparation started");
        StartedAt ??= at;

        foreach (var item in _items.Where(i => i.Status == OrderItemStatus.Pending))
        {
            item.MarkInPreparation();
        }
    }

    public void MarkPartiallyReady(Guid? changedBy, DateTimeOffset at)
        => ChangeStatus(OrderStatus.PartiallyReady, changedBy, at, "Some stations are ready");

    public void MarkReady(Guid? changedBy, DateTimeOffset at)
    {
        ChangeStatus(OrderStatus.Ready, changedBy, at, "All stations ready");
        ReadyAt = at;

        foreach (var item in _items.Where(i => i.Status != OrderItemStatus.Cancelled))
        {
            item.MarkReady();
        }

        Raise(new OrderReadyDomainEvent(RestaurantId, Id, OrderNumber.Value, TableId, WaiterId, at));
    }

    public void Serve(Guid servedBy, DateTimeOffset at)
    {
        Guard.NotEmpty(servedBy);
        ChangeStatus(OrderStatus.Served, servedBy, at, "Served to the guest");
        ServedAt = at;
        ServedBy = servedBy;

        foreach (var item in _items.Where(i => i.Status != OrderItemStatus.Cancelled))
        {
            item.MarkServed();
        }

        Raise(new OrderServedDomainEvent(RestaurantId, Id, TableId, servedBy, at));
    }

    public void Cancel(Guid cancelledBy, string reason, DateTimeOffset at)
    {
        Guard.NotEmpty(cancelledBy);
        Guard.NotNullOrWhiteSpace(reason);

        ChangeStatus(OrderStatus.Cancelled, cancelledBy, at, reason);
        CancelledAt = at;
        CancelledBy = cancelledBy;
        CancellationReason = Guard.MaxLength(reason.Trim(), 300);

        foreach (var item in _items)
        {
            item.MarkCancelled();
        }

        Raise(new OrderCancelledDomainEvent(RestaurantId, Id, TableId, cancelledBy, CancellationReason, at));
    }

    /// <summary>Called by the billing module once the order is fully paid.</summary>
    public void Close(Guid closedBy, DateTimeOffset at)
    {
        Guard.NotEmpty(closedBy);
        ChangeStatus(OrderStatus.Closed, closedBy, at, "Fully paid");
        ClosedAt = at;
        ClosedBy = closedBy;

        Raise(new OrderClosedDomainEvent(RestaurantId, Id, TableSessionId, closedBy, at));
    }

    /// <summary>
    /// Applies a discount, capped by the venue's configured maximum. The cap is
    /// passed in rather than read from settings so the aggregate stays free of
    /// repository lookups.
    /// </summary>
    public void ApplyDiscount(Percentage discount, Percentage maxAllowed, string reason, Guid appliedBy, DateTimeOffset at)
    {
        if (IsSettled)
        {
            throw new DomainExceptions.BusinessRuleViolationException(
                ErrorCodes.OrderImmutable,
                "A closed or cancelled order cannot be discounted.");
        }

        if (discount.Value > maxAllowed.Value)
        {
            throw new DomainExceptions.BusinessRuleViolationException(
                ErrorCodes.Forbidden,
                $"The discount exceeds the maximum allowed ({maxAllowed}).");
        }

        Guard.NotNullOrWhiteSpace(reason);
        DiscountReason = Guard.MaxLength(reason.Trim(), 300);
        DiscountAmount = discount.AppliedTo(Subtotal);
        Recalculate(keepDiscount: true);

        _statusHistory.Add(OrderStatusHistory.Record(
            Id, Status, Status, appliedBy, at, $"Discount {discount} applied: {DiscountReason}"));
    }

    public void RemoveDiscount(Guid removedBy, DateTimeOffset at)
    {
        DiscountAmount = Money.Zero(Currency);
        DiscountReason = null;
        Recalculate();
        _statusHistory.Add(OrderStatusHistory.Record(Id, Status, Status, removedBy, at, "Discount removed"));
    }

    /// <summary>
    /// Central status gate. Every path in and out of a status goes through here,
    /// so no code path can change a status without writing history (RG-048/049).
    /// </summary>
    private void ChangeStatus(OrderStatus newStatus, Guid? changedBy, DateTimeOffset at, string? note)
    {
        if (Status == newStatus)
        {
            return;
        }

        if (!OrderStatusTransitions.IsAllowed(Status, newStatus))
        {
            throw new DomainExceptions.BusinessRuleViolationException(
                ErrorCodes.InvalidOrderStatusTransition,
                $"An order cannot go from {Status} to {newStatus}.");
        }

        var previous = Status;
        Status = newStatus;
        _statusHistory.Add(OrderStatusHistory.Record(Id, previous, newStatus, changedBy, at, note));
        Raise(new OrderStatusChangedDomainEvent(RestaurantId, Id, previous, newStatus, changedBy, at));
    }

    private void Recalculate(bool keepDiscount = false)
    {
        foreach (var item in _items)
        {
            item.Recalculate(TaxIncludedInPrice);
        }

        var live = _items.Where(i => i.Status != OrderItemStatus.Cancelled).ToList();

        Subtotal = Money.Sum(live.Select(i => i.LineSubtotal), Currency);
        TaxAmount = Money.Sum(live.Select(i => i.LineTax), Currency);
        ServiceChargeAmount = ServiceChargeRate.AppliedTo(Subtotal);

        if (!keepDiscount)
        {
            DiscountAmount = Money.Zero(Currency);
        }

        var gross = TaxIncludedInPrice
            ? Subtotal.Add(ServiceChargeAmount)
            : Subtotal.Add(TaxAmount).Add(ServiceChargeAmount);

        var total = gross.Subtract(DiscountAmount);
        Total = total.IsNegative ? Money.Zero(Currency) : total;
    }

    private OrderItem FindItem(Guid orderItemId)
        => _items.SingleOrDefault(i => i.Id == orderItemId)
           ?? throw new DomainExceptions.BusinessRuleViolationException(
               ErrorCodes.NotFound,
               "This order line does not exist.");

    private void EnsureEditable()
    {
        if (!IsEditable)
        {
            throw new DomainExceptions.BusinessRuleViolationException(
                ErrorCodes.OrderImmutable,
                $"An order in status {Status} can no longer be edited.");
        }
    }

    private void EnsureHasItems()
    {
        if (_items.Count == 0)
        {
            throw new DomainExceptions.BusinessRuleViolationException(
                ErrorCodes.OrderEmpty,
                "An order must contain at least one line.");
        }
    }
}
