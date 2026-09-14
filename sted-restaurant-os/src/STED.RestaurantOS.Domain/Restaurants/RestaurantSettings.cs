using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.ValueObjects;

namespace STED.RestaurantOS.Domain.Restaurants;

/// <summary>
/// Per-venue operational configuration. Part of the <see cref="Restaurant"/>
/// aggregate: it is meaningless on its own and always changes with its owner.
/// </summary>
public sealed class RestaurantSettings : Entity
{
    private RestaurantSettings()
    {
    }

    private RestaurantSettings(Guid restaurantId)
        : base(restaurantId)
        => RestaurantId = restaurantId;

    /// <summary>Also the primary key: one settings row per restaurant, always.</summary>
    public Guid RestaurantId { get; private set; }

    public TaxRate DefaultTaxRate { get; private set; } = TaxRate.Zero;

    /// <summary>When true, menu prices already include tax (tax is extracted, not added).</summary>
    public bool TaxIncludedInPrice { get; private set; }

    public Percentage ServiceChargeRate { get; private set; } = Percentage.Zero;

    /// <summary>
    /// When true a guest QR order lands as PENDING and a waiter must confirm it
    /// before the kitchen sees it. Default false: the whole point of the product
    /// is that the kitchen starts without waiting for a waiter. Venues that want
    /// a human gate can turn it on.
    /// </summary>
    public bool OrderRequiresWaiterConfirmation { get; private set; }

    public bool AllowGuestOrdering { get; private set; } = true;

    public int MaxOpenOrdersPerSession { get; private set; } = 20;

    /// <summary>Ticket turns amber past this many minutes.</summary>
    public int KdsWarningThresholdMinutes { get; private set; } = 8;

    /// <summary>Ticket turns red (late) past this many minutes.</summary>
    public int KdsLateThresholdMinutes { get; private set; } = 15;

    public int BarWarningThresholdMinutes { get; private set; } = 3;

    public int BarLateThresholdMinutes { get; private set; } = 5;

    /// <summary>0 disables scheduled QR rotation.</summary>
    public int QrTokenRotationDays { get; private set; }

    /// <summary>Hours a guest token stays valid, capped by session closure.</summary>
    public int GuestTokenLifetimeHours { get; private set; } = 4;

    public Percentage MaxDiscountPercentage { get; private set; } = Percentage.Of(20m);

    public string? ReceiptHeader { get; private set; }

    public string? ReceiptFooter { get; private set; }

    public string? PrinterConfigJson { get; private set; }

    public string? OpeningHoursJson { get; private set; }

    public static RestaurantSettings CreateDefault(Guid restaurantId) => new(restaurantId);

    public void UpdateTaxes(TaxRate defaultTaxRate, bool taxIncludedInPrice, Percentage serviceChargeRate)
    {
        DefaultTaxRate = defaultTaxRate;
        TaxIncludedInPrice = taxIncludedInPrice;
        ServiceChargeRate = serviceChargeRate;
    }

    public void UpdateOrderingRules(
        bool orderRequiresWaiterConfirmation,
        bool allowGuestOrdering,
        int maxOpenOrdersPerSession)
    {
        OrderRequiresWaiterConfirmation = orderRequiresWaiterConfirmation;
        AllowGuestOrdering = allowGuestOrdering;
        MaxOpenOrdersPerSession = maxOpenOrdersPerSession < 1 ? 1 : maxOpenOrdersPerSession;
    }

    public void UpdateKdsThresholds(int warningMinutes, int lateMinutes, int barWarningMinutes, int barLateMinutes)
    {
        KdsWarningThresholdMinutes = warningMinutes < 1 ? 1 : warningMinutes;
        KdsLateThresholdMinutes = lateMinutes <= warningMinutes ? warningMinutes + 1 : lateMinutes;
        BarWarningThresholdMinutes = barWarningMinutes < 1 ? 1 : barWarningMinutes;
        BarLateThresholdMinutes = barLateMinutes <= barWarningMinutes ? barWarningMinutes + 1 : barLateMinutes;
    }

    public void UpdateSecurity(int qrTokenRotationDays, int guestTokenLifetimeHours)
    {
        QrTokenRotationDays = qrTokenRotationDays < 0 ? 0 : qrTokenRotationDays;
        GuestTokenLifetimeHours = guestTokenLifetimeHours is < 1 or > 24 ? 4 : guestTokenLifetimeHours;
    }

    public void UpdateReceipt(string? header, string? footer, string? printerConfigJson)
    {
        ReceiptHeader = header;
        ReceiptFooter = footer;
        PrinterConfigJson = printerConfigJson;
    }

    public void UpdateMaxDiscount(Percentage maxDiscount) => MaxDiscountPercentage = maxDiscount;

    public void UpdateOpeningHours(string? openingHoursJson) => OpeningHoursJson = openingHoursJson;
}
