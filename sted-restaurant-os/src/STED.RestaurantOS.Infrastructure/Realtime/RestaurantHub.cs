using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.SignalR;
using Microsoft.Extensions.Logging;
using STED.RestaurantOS.Infrastructure.Identity;

namespace STED.RestaurantOS.Infrastructure.Realtime;

/// <summary>
/// One hub, many groups.
/// <para>
/// Group membership is decided here, on the server, from the claims in the
/// token. There is deliberately no method a client can call to join a group: if
/// there were, tenant isolation would be one line of browser JavaScript away
/// from being bypassed.
/// </para>
/// </summary>
[Authorize]
public sealed class RestaurantHub : Hub
{
    private readonly ILogger<RestaurantHub> _logger;

    public RestaurantHub(ILogger<RestaurantHub> logger) => _logger = logger;

    public override async Task OnConnectedAsync()
    {
        var user = Context.User;

        if (user is null)
        {
            Context.Abort();
            return;
        }

        var restaurantId = ReadGuid(user, AppClaims.RestaurantId);

        if (restaurantId is null)
        {
            Context.Abort();
            return;
        }

        // Guests are recognised by their session claim. They join their own
        // table and nothing else — not the restaurant group, which carries every
        // other table's traffic.
        var sessionId = ReadGuid(user, AppClaims.TableSessionId);
        var tableId = ReadGuid(user, AppClaims.TableId);

        if (sessionId is not null && tableId is not null)
        {
            await Groups.AddToGroupAsync(Context.ConnectionId, RealtimeGroups.Table(tableId.Value));
            await base.OnConnectedAsync();
            return;
        }

        await Groups.AddToGroupAsync(Context.ConnectionId, RealtimeGroups.Restaurant(restaurantId.Value));

        if (ReadGuid(user, ClaimTypes.NameIdentifier) is { } userId)
        {
            await Groups.AddToGroupAsync(Context.ConnectionId, RealtimeGroups.User(userId));
        }

        foreach (var role in user.FindAll(ClaimTypes.Role).Select(c => c.Value))
        {
            await Groups.AddToGroupAsync(
                Context.ConnectionId, RealtimeGroups.Role(restaurantId.Value, role));
        }

        await base.OnConnectedAsync();
    }

    /// <summary>
    /// A kitchen or bar screen asks to watch one station.
    /// <para>
    /// The request is honoured only after checking the caller holds the matching
    /// permission — a client naming a group is a request, never an instruction.
    /// </para>
    /// </summary>
    public async Task WatchStation(Guid stationId)
    {
        var user = Context.User;

        var allowed = user is not null && user.FindAll(AppClaims.Permission).Any(c =>
            c.Value is Application.Security.Permissions.KitchenView
                or Application.Security.Permissions.BarView
                or Application.Security.Permissions.OrdersView);

        if (!allowed)
        {
            _logger.LogWarning(
                "Connection {ConnectionId} tried to watch station {StationId} without permission.",
                Context.ConnectionId,
                stationId);

            throw new HubException("You are not allowed to watch this station.");
        }

        await Groups.AddToGroupAsync(Context.ConnectionId, RealtimeGroups.Station(stationId));
    }

    public Task UnwatchStation(Guid stationId)
        => Groups.RemoveFromGroupAsync(Context.ConnectionId, RealtimeGroups.Station(stationId));

    private static Guid? ReadGuid(ClaimsPrincipal user, string claimType)
    {
        var value = user.FindFirstValue(claimType) ?? user.FindFirstValue("sub");
        return Guid.TryParse(value, out var id) ? id : null;
    }
}
