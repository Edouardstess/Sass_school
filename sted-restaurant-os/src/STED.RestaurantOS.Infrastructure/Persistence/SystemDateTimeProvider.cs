using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Infrastructure.Persistence;

/// <summary>The real clock. Tests substitute their own.</summary>
public sealed class SystemDateTimeProvider : IDateTimeProvider
{
    public DateTimeOffset UtcNow => DateTimeOffset.UtcNow;
}
