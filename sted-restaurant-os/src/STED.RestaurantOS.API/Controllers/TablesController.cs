using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Floor.Dtos;
using STED.RestaurantOS.Application.Security;

namespace STED.RestaurantOS.API.Controllers;

[Route("api/v1/tables")]
[Authorize]
public sealed class TablesController : ApiControllerBase
{
    private readonly IFloorService _floor;

    public TablesController(IFloorService floor) => _floor = floor;

    /// <summary>The floor plan, with live service state on every table.</summary>
    [HttpGet]
    [Authorize(Policy = Permissions.TablesView)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    public async Task<IActionResult> GetFloorPlan([FromQuery] Guid? zoneId, CancellationToken ct)
        => Payload(await _floor.GetFloorPlanAsync(zoneId, ct));

    [HttpGet("{tableId:guid}")]
    [Authorize(Policy = Permissions.TablesView)]
    public async Task<IActionResult> GetTable(Guid tableId, CancellationToken ct)
        => Ok(await _floor.GetTableAsync(tableId, ct));

    [HttpPost]
    [Authorize(Policy = Permissions.RestaurantManage)]
    public async Task<IActionResult> CreateTable(
        [FromBody] CreateTableRequest request,
        CancellationToken ct)
        => Ok(await _floor.CreateTableAsync(request, ct));

    /// <summary>
    /// Takes responsibility for a table. If two waiters tap at the same instant
    /// exactly one succeeds; the other is told who got it.
    /// </summary>
    [HttpPost("{tableId:guid}/take")]
    [Authorize(Policy = Permissions.TablesAssign)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status409Conflict)]
    public async Task<IActionResult> TakeTable(
        Guid tableId,
        [FromBody] TakeTableRequest request,
        CancellationToken ct)
        => Ok(await _floor.TakeTableAsync(tableId, request, ct));

    /// <summary>
    /// Hands a table to another waiter. Orders already placed keep their
    /// original waiter; only orders placed from now on belong to the new one.
    /// </summary>
    [HttpPost("{tableId:guid}/transfer")]
    [Authorize(Policy = Permissions.TablesTransfer)]
    public async Task<IActionResult> TransferTable(
        Guid tableId,
        [FromBody] TransferTableRequest request,
        CancellationToken ct)
        => Ok(await _floor.TransferTableAsync(tableId, request, ct));

    [HttpPost("{tableId:guid}/release")]
    [Authorize(Policy = Permissions.TablesAssign)]
    public async Task<IActionResult> ReleaseTable(
        Guid tableId,
        [FromBody] ReleaseTableRequest request,
        CancellationToken ct)
        => Ok(await _floor.ReleaseTableAsync(tableId, request, ct));

    /// <summary>
    /// Issues a fresh QR code and retires the old one. The token is in the
    /// response and nowhere else — print it now or regenerate again.
    /// </summary>
    [HttpPost("{tableId:guid}/qr/regenerate")]
    [Authorize(Policy = Permissions.RestaurantManage)]
    public async Task<IActionResult> RegenerateQrCode(Guid tableId, CancellationToken ct)
        => Ok(await _floor.RegenerateQrCodeAsync(tableId, ct));
}

[Route("api/v1/table-sessions")]
[Authorize]
public sealed class TableSessionsController : ApiControllerBase
{
    private readonly IFloorService _floor;

    public TableSessionsController(IFloorService floor) => _floor = floor;

    [HttpGet("{sessionId:guid}")]
    [Authorize(Policy = Permissions.TablesView)]
    public async Task<IActionResult> GetSession(Guid sessionId, CancellationToken ct)
        => Ok(await _floor.GetSessionAsync(sessionId, ct));

    /// <summary>Ends the service episode. Refused while any order is unsettled.</summary>
    [HttpPost("{sessionId:guid}/close")]
    [Authorize(Policy = Permissions.TablesAssign)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status409Conflict)]
    public async Task<IActionResult> CloseSession(Guid sessionId, CancellationToken ct)
        => Ok(await _floor.CloseSessionAsync(sessionId, ct));
}

[Route("api/v1/service-assignments")]
[Authorize]
public sealed class ServiceAssignmentsController : ApiControllerBase
{
    private readonly IFloorService _floor;

    public ServiceAssignmentsController(IFloorService floor) => _floor = floor;

    /// <summary>
    /// "Who was responsible for this table at this moment?" — answered from the
    /// assignment windows, and cross-checkable against the history below.
    /// </summary>
    [HttpGet("at")]
    [Authorize(Policy = Permissions.TablesView)]
    public async Task<IActionResult> GetResponsibleWaiter(
        [FromQuery] Guid tableId,
        [FromQuery] DateTimeOffset at,
        CancellationToken ct)
        => Ok(await _floor.GetResponsibleWaiterAsync(tableId, at, ct));

    /// <summary>The hand-over trail. Append-only: no endpoint writes here.</summary>
    [HttpGet("history")]
    [Authorize(Policy = Permissions.TablesView)]
    public async Task<IActionResult> GetHistory(
        [FromQuery] Guid? tableId,
        [FromQuery] Guid? waiterId,
        [FromQuery] DateTimeOffset? from,
        [FromQuery] DateTimeOffset? to,
        CancellationToken ct)
        => Payload(await _floor.GetAssignmentHistoryAsync(tableId, waiterId, from, to, ct));
}

[Route("api/v1/waiter")]
[Authorize]
public sealed class WaiterController : ApiControllerBase
{
    private readonly IFloorService _floor;

    public WaiterController(IFloorService floor) => _floor = floor;

    /// <summary>The waiter's own screen: the tables they are responsible for.</summary>
    [HttpGet("me/tables")]
    [Authorize(Policy = Permissions.TablesView)]
    public async Task<IActionResult> GetMyTables(CancellationToken ct)
        => Payload(await _floor.GetMyTablesAsync(ct));
}
