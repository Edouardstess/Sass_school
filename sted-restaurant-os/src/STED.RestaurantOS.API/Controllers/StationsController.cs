using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Security;
using STED.RestaurantOS.Domain.Catalog;

namespace STED.RestaurantOS.API.Controllers;

/// <summary>
/// The kitchen screen. Identical machinery to the bar, different station and
/// different lateness thresholds.
/// </summary>
[Route("api/v1/kitchen")]
[Authorize]
public sealed class KitchenController : ApiControllerBase
{
    private readonly IPreparationService _preparation;

    public KitchenController(IPreparationService preparation) => _preparation = preparation;

    /// <summary>
    /// The entire screen in one call.
    /// <para>
    /// This is what the client fetches on reconnect and on a fifteen-second
    /// timer. Real-time push makes it feel instant; this endpoint is what makes
    /// it correct. A kitchen display that silently stops updating stops the
    /// service, so it never depends on the socket alone.
    /// </para>
    /// </summary>
    [HttpGet("snapshot")]
    [Authorize(Policy = Permissions.KitchenView)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    public async Task<IActionResult> GetSnapshot(CancellationToken ct)
        => Ok(await _preparation.GetSnapshotByCodeAsync(StationCodes.Kitchen, ct));

    [HttpPost("tickets/{ticketId:guid}/accept")]
    [Authorize(Policy = Permissions.KitchenManage)]
    public async Task<IActionResult> Accept(Guid ticketId, CancellationToken ct)
        => Ok(await _preparation.AcceptAsync(ticketId, ct));

    [HttpPost("tickets/{ticketId:guid}/start")]
    [Authorize(Policy = Permissions.KitchenManage)]
    public async Task<IActionResult> Start(Guid ticketId, CancellationToken ct)
        => Ok(await _preparation.StartAsync(ticketId, ct));

    /// <summary>
    /// This station is done. When every station on the order is done the order
    /// becomes READY and the waiter is buzzed; until then it is partially ready,
    /// so drinks can go out while a main course finishes.
    /// </summary>
    [HttpPost("tickets/{ticketId:guid}/ready")]
    [Authorize(Policy = Permissions.KitchenManage)]
    public async Task<IActionResult> MarkReady(Guid ticketId, CancellationToken ct)
        => Ok(await _preparation.MarkReadyAsync(ticketId, ct));

    [HttpPost("tickets/{ticketId:guid}/picked-up")]
    [Authorize(Policy = Permissions.KitchenView)]
    public async Task<IActionResult> MarkPickedUp(Guid ticketId, CancellationToken ct)
        => Ok(await _preparation.MarkPickedUpAsync(ticketId, ct));
}

/// <summary>The bar screen. Same engine, tighter clock.</summary>
[Route("api/v1/bar")]
[Authorize]
public sealed class BarController : ApiControllerBase
{
    private readonly IPreparationService _preparation;

    public BarController(IPreparationService preparation) => _preparation = preparation;

    [HttpGet("snapshot")]
    [Authorize(Policy = Permissions.BarView)]
    public async Task<IActionResult> GetSnapshot(CancellationToken ct)
        => Ok(await _preparation.GetSnapshotByCodeAsync(StationCodes.Bar, ct));

    [HttpPost("tickets/{ticketId:guid}/accept")]
    [Authorize(Policy = Permissions.BarManage)]
    public async Task<IActionResult> Accept(Guid ticketId, CancellationToken ct)
        => Ok(await _preparation.AcceptAsync(ticketId, ct));

    [HttpPost("tickets/{ticketId:guid}/start")]
    [Authorize(Policy = Permissions.BarManage)]
    public async Task<IActionResult> Start(Guid ticketId, CancellationToken ct)
        => Ok(await _preparation.StartAsync(ticketId, ct));

    [HttpPost("tickets/{ticketId:guid}/ready")]
    [Authorize(Policy = Permissions.BarManage)]
    public async Task<IActionResult> MarkReady(Guid ticketId, CancellationToken ct)
        => Ok(await _preparation.MarkReadyAsync(ticketId, ct));

    [HttpPost("tickets/{ticketId:guid}/picked-up")]
    [Authorize(Policy = Permissions.BarView)]
    public async Task<IActionResult> MarkPickedUp(Guid ticketId, CancellationToken ct)
        => Ok(await _preparation.MarkPickedUpAsync(ticketId, ct));
}
