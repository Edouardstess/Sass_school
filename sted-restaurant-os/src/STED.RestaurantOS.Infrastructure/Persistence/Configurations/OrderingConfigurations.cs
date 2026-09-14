using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.Restaurants;
using STED.RestaurantOS.Domain.Staff;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Infrastructure.Persistence.Converters;

namespace STED.RestaurantOS.Infrastructure.Persistence.Configurations;

internal sealed class OrderConfiguration : IEntityTypeConfiguration<Order>
{
    public void Configure(EntityTypeBuilder<Order> builder)
    {
        builder.ToTable("Orders", t => t.HasCheckConstraint(
            "CK_Orders_Total", "[Total] >= 0"));
        builder.HasKey(o => o.Id);

        builder.Property(o => o.OrderNumber)
            .HasConversion(new OrderNumberConverter())
            .HasMaxLength(OrderNumber.MaxLength)
            .IsUnicode(false)
            .IsRequired();

        builder.Property(o => o.Status).HasEnumStringConversion().IsRequired();
        builder.Property(o => o.Source).HasEnumStringConversion(12).IsRequired();
        builder.Property(o => o.Currency).HasMaxLength(3).IsFixedLength().IsUnicode(false).IsRequired();
        builder.Property(o => o.ServiceChargeRate).HasPercentageConversion().IsRequired();
        builder.Property(o => o.Notes).HasMaxLength(500);
        builder.Property(o => o.DiscountReason).HasMaxLength(300);
        builder.Property(o => o.CancellationReason).HasMaxLength(300);
        builder.Property(o => o.RowVersion).IsRowVersion();

        builder.OwnsOne(o => o.Subtotal, m => m.ConfigureMoney("Subtotal"));
        builder.OwnsOne(o => o.TaxAmount, m => m.ConfigureMoney("TaxAmount"));
        builder.OwnsOne(o => o.DiscountAmount, m => m.ConfigureMoney("DiscountAmount"));
        builder.OwnsOne(o => o.ServiceChargeAmount, m => m.ConfigureMoney("ServiceChargeAmount"));
        builder.OwnsOne(o => o.Total, m => m.ConfigureMoney("Total"));

        builder.Navigation(o => o.Subtotal).IsRequired();
        builder.Navigation(o => o.TaxAmount).IsRequired();
        builder.Navigation(o => o.DiscountAmount).IsRequired();
        builder.Navigation(o => o.ServiceChargeAmount).IsRequired();
        builder.Navigation(o => o.Total).IsRequired();


        builder.HasIndex(o => new { o.RestaurantId, o.OrderNumber })
            .IsUnique()
            .HasDatabaseName("UX_Orders_Restaurant_OrderNumber");

        // Kitchen, bar and admin dashboards.
        builder.HasIndex(o => new { o.RestaurantId, o.Status, o.CreatedAt })
            .HasDatabaseName("IX_Orders_Restaurant_Status_CreatedAt");

        // Waiter performance reports: covering, so the aggregate never touches the table.
        builder.HasIndex(o => new { o.RestaurantId, o.WaiterId, o.CreatedAt })
            .IncludeProperties(o => o.Status)
            .HasDatabaseName("IX_Orders_Restaurant_Waiter_CreatedAt");

        builder.HasIndex(o => o.TableSessionId).HasDatabaseName("IX_Orders_TableSession");

        builder.HasIndex(o => new { o.RestaurantId, o.CreatedAt })
            .HasDatabaseName("IX_Orders_Restaurant_CreatedAt");

        builder.HasOne<Restaurant>().WithMany().HasForeignKey(o => o.RestaurantId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.HasOne<RestaurantTable>().WithMany().HasForeignKey(o => o.TableId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.HasOne<TableSession>().WithMany().HasForeignKey(o => o.TableSessionId)
            .OnDelete(DeleteBehavior.Restrict);

        // The frozen fact: which waiter owns this order. A later table transfer
        // never touches this column.
        builder.HasOne<StaffProfile>().WithMany().HasForeignKey(o => o.WaiterId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.HasMany(o => o.Items)
            .WithOne()
            .HasForeignKey(i => i.OrderId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.HasMany(o => o.StatusHistory)
            .WithOne()
            .HasForeignKey(h => h.OrderId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.Metadata.FindNavigation(nameof(Order.Items))!
            .SetPropertyAccessMode(PropertyAccessMode.Field);
        builder.Metadata.FindNavigation(nameof(Order.StatusHistory))!
            .SetPropertyAccessMode(PropertyAccessMode.Field);

        builder.Ignore(o => o.DomainEvents);
        builder.Ignore(o => o.ItemCount);
        builder.Ignore(o => o.IsSettled);
        builder.Ignore(o => o.IsEditable);
        builder.Ignore(o => o.StationIds);
    }
}

internal sealed class OrderItemConfiguration : IEntityTypeConfiguration<OrderItem>
{
    public void Configure(EntityTypeBuilder<OrderItem> builder)
    {
        builder.ToTable("OrderItems", t => t.HasCheckConstraint(
            "CK_OrderItems_Quantity", "[Quantity] BETWEEN 1 AND 99"));
        builder.HasKey(i => i.Id);

        // Snapshots: the whole point is that these do not follow the catalogue.
        builder.Property(i => i.ProductNameSnapshot).HasMaxLength(160).IsRequired();
        builder.Property(i => i.StationCodeSnapshot).HasMaxLength(20).IsUnicode(false).IsRequired();
        builder.Property(i => i.TaxRateSnapshot).HasTaxRateConversion().IsRequired();
        builder.Property(i => i.Status).HasEnumStringConversion().IsRequired();
        builder.Property(i => i.Notes).HasMaxLength(300);
        builder.Property(i => i.Currency).HasMaxLength(3).IsFixedLength().IsUnicode(false).IsRequired();

        builder.OwnsOne(i => i.UnitPriceSnapshot, m => m.ConfigureMoney("UnitPriceSnapshot"));
        builder.OwnsOne(i => i.ModifiersUnitTotal, m => m.ConfigureMoney("ModifiersUnitTotal"));
        builder.OwnsOne(i => i.LineSubtotal, m => m.ConfigureMoney("LineSubtotal"));
        builder.OwnsOne(i => i.LineTax, m => m.ConfigureMoney("LineTax"));
        builder.OwnsOne(i => i.LineTotal, m => m.ConfigureMoney("LineTotal"));

        builder.Navigation(i => i.UnitPriceSnapshot).IsRequired();
        builder.Navigation(i => i.ModifiersUnitTotal).IsRequired();
        builder.Navigation(i => i.LineSubtotal).IsRequired();
        builder.Navigation(i => i.LineTax).IsRequired();
        builder.Navigation(i => i.LineTotal).IsRequired();


        // Splitting one order into per-station tickets.
        builder.HasIndex(i => new { i.OrderId, i.StationId })
            .HasDatabaseName("IX_OrderItems_Order_Station");

        builder.HasIndex(i => i.ProductId).HasDatabaseName("IX_OrderItems_Product");

        builder.HasMany(i => i.Modifiers)
            .WithOne()
            .HasForeignKey(m => m.OrderItemId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.Metadata.FindNavigation(nameof(OrderItem.Modifiers))!
            .SetPropertyAccessMode(PropertyAccessMode.Field);

        builder.Ignore(i => i.EffectiveUnitPrice);
    }
}

internal sealed class OrderItemModifierConfiguration : IEntityTypeConfiguration<OrderItemModifier>
{
    public void Configure(EntityTypeBuilder<OrderItemModifier> builder)
    {
        builder.ToTable("OrderItemModifiers");
        builder.HasKey(m => m.Id);

        builder.Property(m => m.ModifierNameSnapshot).HasMaxLength(120).IsRequired();
        builder.Property(m => m.OptionNameSnapshot).HasMaxLength(120).IsRequired();

        builder.OwnsOne(m => m.PriceDeltaSnapshot, x => x.ConfigureMoney("PriceDeltaSnapshot"));
        builder.Navigation(m => m.PriceDeltaSnapshot).IsRequired();
    }
}

internal sealed class OrderStatusHistoryConfiguration : IEntityTypeConfiguration<OrderStatusHistory>
{
    public void Configure(EntityTypeBuilder<OrderStatusHistory> builder)
    {
        builder.ToTable("OrderStatusHistory");
        builder.HasKey(h => h.Id);

        builder.Property(h => h.PreviousStatus).HasEnumStringConversion();
        builder.Property(h => h.NewStatus).HasEnumStringConversion().IsRequired();
        builder.Property(h => h.Note).HasMaxLength(300);

        builder.HasIndex(h => new { h.OrderId, h.ChangedAt })
            .HasDatabaseName("IX_OrderStatusHistory_Order_ChangedAt");
    }
}
