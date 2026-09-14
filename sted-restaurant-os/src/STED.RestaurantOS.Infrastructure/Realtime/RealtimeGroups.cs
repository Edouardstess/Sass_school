namespace STED.RestaurantOS.Infrastructure.Realtime;

/// <summary>
/// Group names, in one place so the hub and the notifier cannot drift apart.
/// </summary>
public static class RealtimeGroups
{
    public static string Restaurant(Guid restaurantId) => $"restaurant:{restaurantId}";

    public static string Station(Guid stationId) => $"station:{stationId}";

    public static string Table(Guid tableId) => $"table:{tableId}";

    public static string User(Guid userId) => $"user:{userId}";

    public static string Role(Guid restaurantId, string role) => $"role:{restaurantId}:{role}";
}
