using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using STED.RestaurantOS.Domain.Catalog;
using STED.RestaurantOS.Domain.Restaurants;

namespace STED.RestaurantOS.Infrastructure.Persistence.Configurations;

internal sealed class StationConfiguration : IEntityTypeConfiguration<Station>
{
    public void Configure(EntityTypeBuilder<Station> builder)
    {
        builder.ToTable("Stations");
        builder.HasKey(s => s.Id);

        builder.Property(s => s.Code).HasMaxLength(20).IsUnicode(false).IsRequired();
        builder.Property(s => s.Name).HasMaxLength(80).IsRequired();

        builder.HasIndex(s => new { s.RestaurantId, s.Code })
            .IsUnique()
            .HasDatabaseName("UX_Stations_Restaurant_Code");

        builder.HasOne<Restaurant>().WithMany().HasForeignKey(s => s.RestaurantId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.Ignore(s => s.DomainEvents);
    }
}

internal sealed class MenuCategoryConfiguration : IEntityTypeConfiguration<MenuCategory>
{
    public void Configure(EntityTypeBuilder<MenuCategory> builder)
    {
        builder.ToTable("MenuCategories");
        builder.HasKey(c => c.Id);

        builder.Property(c => c.Name).HasMaxLength(120).IsRequired();
        builder.Property(c => c.Description).HasMaxLength(1000);
        builder.Property(c => c.ImageUrl).HasMaxLength(500);

        builder.HasIndex(c => new { c.RestaurantId, c.Name })
            .IsUnique()
            .HasDatabaseName("UX_MenuCategories_Restaurant_Name");

        builder.HasIndex(c => new { c.RestaurantId, c.DisplayOrder })
            .HasDatabaseName("IX_MenuCategories_Restaurant_DisplayOrder");

        builder.HasOne<Restaurant>().WithMany().HasForeignKey(c => c.RestaurantId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.Ignore(c => c.DomainEvents);
    }
}

internal sealed class ProductConfiguration : IEntityTypeConfiguration<Product>
{
    public void Configure(EntityTypeBuilder<Product> builder)
    {
        builder.ToTable("Products", t => t.HasCheckConstraint(
            "CK_Products_Price", "[Price] >= 0"));
        builder.HasKey(p => p.Id);

        builder.Property(p => p.Name).HasMaxLength(160).IsRequired();
        builder.Property(p => p.Description).HasMaxLength(1000);
        builder.Property(p => p.ImageUrl).HasMaxLength(500);
        builder.Property(p => p.TaxRate).HasTaxRateConversion().IsRequired();
        builder.Property(p => p.RowVersion).IsRowVersion();

        builder.OwnsOne(p => p.Price, m => m.ConfigureMoney("Price"));
        builder.Navigation(p => p.Price).IsRequired();


        // The menu query the guest app runs on every scan.
        builder.HasIndex(p => new { p.RestaurantId, p.CategoryId, p.IsActive, p.IsAvailable })
            .HasDatabaseName("IX_Products_Restaurant_Category_Availability");

        builder.HasIndex(p => new { p.RestaurantId, p.StationId })
            .HasDatabaseName("IX_Products_Restaurant_Station");

        builder.HasOne<Restaurant>().WithMany().HasForeignKey(p => p.RestaurantId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.HasOne<MenuCategory>().WithMany().HasForeignKey(p => p.CategoryId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.HasOne<Station>().WithMany().HasForeignKey(p => p.StationId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.HasMany(p => p.Modifiers)
            .WithOne()
            .HasForeignKey(m => m.ProductId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.Metadata.FindNavigation(nameof(Product.Modifiers))!
            .SetPropertyAccessMode(PropertyAccessMode.Field);

        builder.Ignore(p => p.DomainEvents);
        builder.Ignore(p => p.IsOrderable);
    }
}

internal sealed class ProductModifierConfiguration : IEntityTypeConfiguration<ProductModifier>
{
    public void Configure(EntityTypeBuilder<ProductModifier> builder)
    {
        builder.ToTable("ProductModifiers", t => t.HasCheckConstraint(
            "CK_ProductModifiers_Selections",
            "[MinSelections] >= 0 AND [MaxSelections] >= 1 AND [MinSelections] <= [MaxSelections]"));
        builder.HasKey(m => m.Id);

        builder.Property(m => m.Name).HasMaxLength(120).IsRequired();

        builder.HasMany(m => m.Options)
            .WithOne()
            .HasForeignKey(o => o.ProductModifierId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.Metadata.FindNavigation(nameof(ProductModifier.Options))!
            .SetPropertyAccessMode(PropertyAccessMode.Field);
    }
}

internal sealed class ModifierOptionConfiguration : IEntityTypeConfiguration<ModifierOption>
{
    public void Configure(EntityTypeBuilder<ModifierOption> builder)
    {
        builder.ToTable("ModifierOptions");
        builder.HasKey(o => o.Id);

        builder.Property(o => o.Name).HasMaxLength(120).IsRequired();

        builder.OwnsOne(o => o.PriceDelta, m => m.ConfigureMoney("PriceDelta"));
        builder.Navigation(o => o.PriceDelta).IsRequired();
    }
}
