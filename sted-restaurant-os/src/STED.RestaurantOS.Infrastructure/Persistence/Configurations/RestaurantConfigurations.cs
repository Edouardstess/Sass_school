using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using STED.RestaurantOS.Domain.Restaurants;
using STED.RestaurantOS.Domain.Staff;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Infrastructure.Persistence.Converters;

namespace STED.RestaurantOS.Infrastructure.Persistence.Configurations;

internal sealed class RestaurantConfiguration : IEntityTypeConfiguration<Restaurant>
{
    public void Configure(EntityTypeBuilder<Restaurant> builder)
    {
        builder.ToTable("Restaurants");
        builder.HasKey(r => r.Id);

        builder.Property(r => r.TenantId).IsRequired();
        builder.Property(r => r.Name).HasMaxLength(200).IsRequired();

        builder.Property(r => r.Slug)
            .HasConversion(new SlugConverter())
            .HasMaxLength(Slug.MaxLength)
            .IsUnicode(false)
            .IsRequired();

        builder.Property(r => r.Address).HasMaxLength(400);
        builder.Property(r => r.Phone).HasMaxLength(30);
        builder.Property(r => r.Email).HasMaxLength(200);
        builder.Property(r => r.LogoUrl).HasMaxLength(500);

        builder.Property(r => r.Currency).HasMaxLength(3).IsFixedLength().IsUnicode(false).IsRequired();
        builder.Property(r => r.Timezone).HasMaxLength(60).IsUnicode(false).IsRequired();

        builder.HasIndex(r => r.Slug).IsUnique().HasDatabaseName("UX_Restaurants_Slug");
        builder.HasIndex(r => r.TenantId).HasDatabaseName("IX_Restaurants_TenantId");

        // Settings live and die with the restaurant: same aggregate, same row lifetime.
        builder.HasOne(r => r.Settings)
            .WithOne()
            .HasForeignKey<RestaurantSettings>(s => s.RestaurantId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.Navigation(r => r.Settings).AutoInclude();

        builder.Ignore(r => r.DomainEvents);
    }
}

internal sealed class RestaurantSettingsConfiguration : IEntityTypeConfiguration<RestaurantSettings>
{
    public void Configure(EntityTypeBuilder<RestaurantSettings> builder)
    {
        builder.ToTable("RestaurantSettings");

        // The restaurant id IS the primary key: one settings row per venue, enforced by the schema.
        builder.HasKey(s => s.RestaurantId);
        builder.Property(s => s.RestaurantId).ValueGeneratedNever();
        builder.Ignore(s => s.Id);

        builder.Property(s => s.DefaultTaxRate).HasTaxRateConversion().IsRequired();
        builder.Property(s => s.ServiceChargeRate).HasPercentageConversion().IsRequired();
        builder.Property(s => s.MaxDiscountPercentage).HasPercentageConversion().IsRequired();

        builder.Property(s => s.ReceiptHeader).HasMaxLength(500);
        builder.Property(s => s.ReceiptFooter).HasMaxLength(500);
        builder.Property(s => s.PrinterConfigJson);
        builder.Property(s => s.OpeningHoursJson);
    }
}

internal sealed class StaffProfileConfiguration : IEntityTypeConfiguration<StaffProfile>
{
    public void Configure(EntityTypeBuilder<StaffProfile> builder)
    {
        builder.ToTable("StaffProfiles");
        builder.HasKey(s => s.Id);

        builder.Property(s => s.RestaurantId).IsRequired();
        builder.Property(s => s.UserId).IsRequired();

        builder.Property(s => s.EmployeeCode)
            .HasConversion(new EmployeeCodeConverter())
            .HasMaxLength(EmployeeCode.MaxLength)
            .IsUnicode(false)
            .IsRequired();

        builder.Property(s => s.DisplayName).HasMaxLength(120).IsRequired();
        builder.Property(s => s.Phone).HasMaxLength(30);
        builder.Property(s => s.PrimaryRole).HasEnumStringConversion(30).IsRequired();

        builder.HasIndex(s => new { s.RestaurantId, s.EmployeeCode })
            .IsUnique()
            .HasDatabaseName("UX_StaffProfiles_Restaurant_EmployeeCode");

        // One operational profile per identity account per venue.
        builder.HasIndex(s => new { s.RestaurantId, s.UserId })
            .IsUnique()
            .HasDatabaseName("UX_StaffProfiles_Restaurant_User");

        builder.HasIndex(s => new { s.RestaurantId, s.IsActive, s.PrimaryRole })
            .HasDatabaseName("IX_StaffProfiles_Restaurant_Active_Role");

        builder.HasOne<Restaurant>()
            .WithMany()
            .HasForeignKey(s => s.RestaurantId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.Ignore(s => s.DomainEvents);
    }
}
