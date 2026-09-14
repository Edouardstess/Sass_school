using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using Microsoft.EntityFrameworkCore.Storage.ValueConversion;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Infrastructure.Persistence.Converters;

namespace STED.RestaurantOS.Infrastructure.Persistence.Configurations;

internal static class ConfigurationExtensions
{
    public const string MoneyColumnType = "decimal(18,2)";
    public const string RateColumnType = "decimal(5,4)";
    public const string PercentageColumnType = "decimal(5,2)";

    /// <summary>
    /// Maps a <see cref="Money"/> property to an amount column plus its own
    /// currency column.
    /// <para>
    /// This is mildly redundant with the currency already stored on the order,
    /// and that is a deliberate trade: a value converter cannot see sibling
    /// properties, so the alternative would be rebuilding Money from a currency
    /// it cannot read. Carrying the currency next to every amount keeps the value
    /// object self-validating, and makes a mixed-currency row impossible to
    /// misread.
    /// </para>
    /// </summary>
    public static OwnedNavigationBuilder<TEntity, Money> ConfigureMoney<TEntity>(
        this OwnedNavigationBuilder<TEntity, Money> builder,
        string columnPrefix)
        where TEntity : class
    {
        builder.Property(m => m.Amount)
            .HasColumnName(columnPrefix)
            .HasColumnType(MoneyColumnType)
            .IsRequired();

        builder.Property(m => m.Currency)
            .HasColumnName($"{columnPrefix}Currency")
            .HasMaxLength(3)
            .IsFixedLength()
            .IsRequired();

        return builder;
    }

    public static PropertyBuilder<TaxRate> HasTaxRateConversion(this PropertyBuilder<TaxRate> builder)
        => builder.HasConversion(new TaxRateConverter()).HasColumnType(RateColumnType);

    public static PropertyBuilder<Percentage> HasPercentageConversion(this PropertyBuilder<Percentage> builder)
        => builder.HasConversion(new PercentageConverter()).HasColumnType(PercentageColumnType);

    /// <summary>
    /// Enums are stored as their names, not their numbers. Reports, support
    /// queries and manual investigation all read this data directly; 'SERVED' in
    /// a SQL window beats '6' every time, and reordering an enum can then never
    /// silently reinterpret existing rows.
    /// </summary>
    public static PropertyBuilder<TEnum> HasEnumStringConversion<TEnum>(
        this PropertyBuilder<TEnum> builder,
        int maxLength = 20)
        where TEnum : struct, Enum
        => builder.HasConversion<string>().HasMaxLength(maxLength).IsUnicode(false);

    /// <summary>Same, for a nullable enum column (a null stays null, not "None").</summary>
    public static PropertyBuilder<TEnum?> HasEnumStringConversion<TEnum>(
        this PropertyBuilder<TEnum?> builder,
        int maxLength = 20)
        where TEnum : struct, Enum
        => builder
            .HasConversion(new ValueConverter<TEnum?, string?>(
                v => v.HasValue ? v.Value.ToString() : null,
                v => v == null ? null : Enum.Parse<TEnum>(v)))
            .HasMaxLength(maxLength)
            .IsUnicode(false);
}
