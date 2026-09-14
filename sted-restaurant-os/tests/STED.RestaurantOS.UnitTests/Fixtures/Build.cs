using STED.RestaurantOS.Domain.Catalog;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.ValueObjects;
using OrderEntity = STED.RestaurantOS.Domain.Ordering.Order;

namespace STED.RestaurantOS.UnitTests.Fixtures;

/// <summary>Terse builders so each test reads as the rule it is checking.</summary>
public static class Build
{
    public const string Currency = "HTG";

    public static readonly Guid RestaurantId = Guid.Parse("11111111-1111-1111-1111-111111111111");
    public static readonly Guid TableId = Guid.Parse("22222222-2222-2222-2222-222222222222");
    public static readonly Guid Jean = Guid.Parse("33333333-3333-3333-3333-333333333333");
    public static readonly Guid Marie = Guid.Parse("44444444-4444-4444-4444-444444444444");
    public static readonly Guid Manager = Guid.Parse("55555555-5555-5555-5555-555555555555");
    public static readonly Guid KitchenStation = Guid.Parse("66666666-6666-6666-6666-666666666666");
    public static readonly Guid BarStation = Guid.Parse("77777777-7777-7777-7777-777777777777");

    public static Money Htg(decimal amount) => Money.Of(amount, Currency);

    public static TableSession Session(DateTimeOffset at, int guests = 2)
        => TableSession.Open(RestaurantId, TableId, 456, guests, Jean, at);

    public static ProductSnapshot Pizza(decimal price = 500m, decimal taxRate = 0.10m)
        => new(
            Guid.NewGuid(),
            "Pizza Margherita",
            Htg(price),
            TaxRate.Of(taxRate),
            KitchenStation,
            StationCodes.Kitchen);

    public static ProductSnapshot Mojito(decimal price = 350m, decimal taxRate = 0.10m)
        => new(
            Guid.NewGuid(),
            "Mojito",
            Htg(price),
            TaxRate.Of(taxRate),
            BarStation,
            StationCodes.Bar);

    public static OrderEntity Order(
        DateTimeOffset at,
        Guid? sessionId = null,
        decimal serviceCharge = 0m,
        bool taxIncluded = false)
        => OrderEntity.Create(
            RestaurantId,
            TableId,
            sessionId ?? Guid.NewGuid(),
            OrderNumber.Create(at, 1),
            OrderSource.Qr,
            Currency,
            taxIncluded,
            Percentage.Of(serviceCharge),
            at);
}
