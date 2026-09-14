using Microsoft.EntityFrameworkCore.Storage.ValueConversion;
using STED.RestaurantOS.Domain.ValueObjects;

namespace STED.RestaurantOS.Infrastructure.Persistence.Converters;

/// <summary>
/// Single-value value objects map to a single column. They are rebuilt through
/// their factory on read, so a row that somehow violates the invariant fails
/// loudly at materialisation instead of silently entering the domain.
/// </summary>
public sealed class TaxRateConverter : ValueConverter<TaxRate, decimal>
{
    public TaxRateConverter()
        : base(v => v.Value, v => TaxRate.Of(v))
    {
    }
}

public sealed class PercentageConverter : ValueConverter<Percentage, decimal>
{
    public PercentageConverter()
        : base(v => v.Value, v => Percentage.Of(v))
    {
    }
}

public sealed class SlugConverter : ValueConverter<Slug, string>
{
    public SlugConverter()
        : base(v => v.Value, v => Slug.Of(v))
    {
    }
}

public sealed class EmployeeCodeConverter : ValueConverter<EmployeeCode, string>
{
    public EmployeeCodeConverter()
        : base(v => v.Value, v => EmployeeCode.Of(v))
    {
    }
}

public sealed class OrderNumberConverter : ValueConverter<OrderNumber, string>
{
    public OrderNumberConverter()
        : base(v => v.Value, v => OrderNumber.Of(v))
    {
    }
}
