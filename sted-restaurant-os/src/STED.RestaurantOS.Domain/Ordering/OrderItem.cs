using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Domain.Ordering;

/// <summary>
/// One line of an order, frozen at confirmation time.
/// <para>
/// Everything ending in <c>Snapshot</c> is a deliberate copy of catalogue data.
/// The product row may later change price, name, station, or disappear entirely;
/// this line must keep telling the truth about what was sold and for how much.
/// </para>
/// </summary>
public sealed class OrderItem : Entity
{
    private readonly List<OrderItemModifier> _modifiers = [];

    private OrderItem()
    {
    }

    private OrderItem(
        Guid id,
        Guid orderId,
        Guid productId,
        string productNameSnapshot,
        Money unitPriceSnapshot,
        TaxRate taxRateSnapshot,
        Guid stationId,
        string stationCodeSnapshot,
        int quantity,
        string? notes)
        : base(id)
    {
        OrderId = orderId;
        ProductId = productId;
        ProductNameSnapshot = productNameSnapshot;
        UnitPriceSnapshot = unitPriceSnapshot;
        TaxRateSnapshot = taxRateSnapshot;
        StationId = stationId;
        StationCodeSnapshot = stationCodeSnapshot;
        Quantity = quantity;
        Notes = notes;
        Status = OrderItemStatus.Pending;
        Currency = unitPriceSnapshot.Currency;
    }

    public Guid OrderId { get; private set; }

    public Guid ProductId { get; private set; }

    public string ProductNameSnapshot { get; private set; } = null!;

    public Money UnitPriceSnapshot { get; private set; } = null!;

    public TaxRate TaxRateSnapshot { get; private set; } = TaxRate.Zero;

    public Guid StationId { get; private set; }

    public string StationCodeSnapshot { get; private set; } = null!;

    public int Quantity { get; private set; }

    public string? Notes { get; private set; }

    public OrderItemStatus Status { get; private set; }

    public string Currency { get; private set; } = null!;

    /// <summary>Sum of the selected modifier deltas, for ONE unit.</summary>
    public Money ModifiersUnitTotal { get; private set; } = null!;

    public Money LineSubtotal { get; private set; } = null!;

    public Money LineTax { get; private set; } = null!;

    public Money LineTotal { get; private set; } = null!;

    public IReadOnlyCollection<OrderItemModifier> Modifiers => _modifiers.AsReadOnly();

    public Money EffectiveUnitPrice => UnitPriceSnapshot.Add(ModifiersUnitTotal);

    internal static OrderItem Create(
        Guid orderId,
        Guid productId,
        string productName,
        Money unitPrice,
        TaxRate taxRate,
        Guid stationId,
        string stationCode,
        int quantity,
        string? notes)
    {
        Guard.NotEmpty(productId);
        Guard.NotNullOrWhiteSpace(productName);
        Guard.InRange(quantity, 1, 99);

        var item = new OrderItem(
            Guid.CreateVersion7(),
            orderId,
            productId,
            Guard.MaxLength(productName, 160),
            unitPrice,
            taxRate,
            stationId,
            stationCode,
            quantity,
            notes);

        item.ModifiersUnitTotal = Money.Zero(unitPrice.Currency);
        item.Recalculate(taxIncludedInPrice: false);
        return item;
    }

    internal void AddModifier(Guid modifierOptionId, string modifierName, string optionName, Money priceDelta)
    {
        _modifiers.Add(OrderItemModifier.Create(Id, modifierOptionId, modifierName, optionName, priceDelta));
        ModifiersUnitTotal = Money.Sum(_modifiers.Select(m => m.PriceDeltaSnapshot), Currency);
    }

    internal void ChangeQuantity(int quantity)
    {
        Quantity = Guard.InRange(quantity, 1, 99);
    }

    internal void UpdateNotes(string? notes) => Notes = notes;

    internal void MarkInPreparation() => Status = OrderItemStatus.InPreparation;

    internal void MarkReady() => Status = OrderItemStatus.Ready;

    internal void MarkServed() => Status = OrderItemStatus.Served;

    internal void MarkCancelled() => Status = OrderItemStatus.Cancelled;

    /// <summary>
    /// Recomputes this line. Rounding happens once here, on the line, and never
    /// on intermediate values, so errors cannot accumulate across an order.
    /// </summary>
    internal void Recalculate(bool taxIncludedInPrice)
    {
        LineSubtotal = EffectiveUnitPrice.Multiply(Quantity);

        if (taxIncludedInPrice)
        {
            // Price already contains the tax: extract it rather than adding it.
            var divisor = 1m + TaxRateSnapshot.Value;
            var net = divisor == 0m ? LineSubtotal.Amount : LineSubtotal.Amount / divisor;
            LineTax = Money.Of(LineSubtotal.Amount - net, Currency);
            LineTotal = LineSubtotal;
        }
        else
        {
            LineTax = LineSubtotal.ApplyRate(TaxRateSnapshot);
            LineTotal = LineSubtotal.Add(LineTax);
        }
    }
}
