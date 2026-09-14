using Microsoft.AspNetCore.SignalR;
using Microsoft.Extensions.Logging;
using STED.RestaurantOS.Application.Abstractions;

namespace STED.RestaurantOS.Infrastructure.Realtime;

/// <summary>
/// Pushes signals to the right groups.
/// <para>
/// Every send is wrapped: a real-time notification is an accelerator, never the
/// system of record. If the hub is down, the order is still committed and every
/// screen still converges on its next poll — so a failure here is logged and
/// swallowed rather than rolled back into the caller's face.
/// </para>
/// </summary>
public sealed class SignalRNotifier : IRealtimeNotifier
{
    private readonly IHubContext<RestaurantHub> _hub;
    private readonly ILogger<SignalRNotifier> _logger;

    public SignalRNotifier(IHubContext<RestaurantHub> hub, ILogger<SignalRNotifier> logger)
    {
        _hub = hub;
        _logger = logger;
    }

    public Task NotifyRestaurantAsync(Guid restaurantId, string eventName, object payload, CancellationToken ct)
        => SendAsync(RealtimeGroups.Restaurant(restaurantId), eventName, payload, ct);

    public Task NotifyStationAsync(Guid stationId, string eventName, object payload, CancellationToken ct)
        => SendAsync(RealtimeGroups.Station(stationId), eventName, payload, ct);

    public Task NotifyTableAsync(Guid tableId, string eventName, object payload, CancellationToken ct)
        => SendAsync(RealtimeGroups.Table(tableId), eventName, payload, ct);

    public Task NotifyUserAsync(Guid userId, string eventName, object payload, CancellationToken ct)
        => SendAsync(RealtimeGroups.User(userId), eventName, payload, ct);

    public Task NotifyRoleAsync(
        Guid restaurantId,
        string role,
        string eventName,
        object payload,
        CancellationToken ct)
        => SendAsync(RealtimeGroups.Role(restaurantId, role), eventName, payload, ct);

    private async Task SendAsync(string group, string eventName, object payload, CancellationToken ct)
    {
        try
        {
            await _hub.Clients.Group(group).SendAsync(eventName, payload, ct);
        }
        catch (Exception exception)
        {
            _logger.LogWarning(
                exception,
                "Could not push {EventName} to {Group}. The data is committed; clients will catch up on their next read.",
                eventName,
                group);
        }
    }
}
