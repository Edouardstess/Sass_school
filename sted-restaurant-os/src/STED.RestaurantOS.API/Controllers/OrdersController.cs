using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.API.Filters;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Ordering.Dtos;
using STED.RestaurantOS.Application.Security;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Shared.Paging;

namespace STED.RestaurantOS.API.Controllers;

[Route("api/v1/orders")]
[Authorize]
public sealed class OrdersController : ApiControllerBase
{
    private readonly IOrderService _orders;

    public OrdersController(IOrderService orders) => _orders = orders;

    [HttpGet]
    [Authorize(Policy = Permissions.OrdersView)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    public async Task<IActionResult> Search(
        [FromQuery] OrderFilter filter,
        [FromQuery] PagedRequest paging,
        CancellationToken ct)
        => Payload(await _orders.SearchAsync(filter, paging, ct));

    [HttpGet("{orderId:guid}")]
    [Authorize(Policy = Permissions.OrdersView)]
    public async Task<IActionResult> GetOrder(Guid orderId, CancellationToken ct)
        => Ok(await _orders.GetOrderAsync(orderId, ct));

    /// <summary>Every status change this order went through, and who made it.</summary>
    [HttpGet("{orderId:guid}/history")]
    [Authorize(Policy = Permissions.OrdersView)]
    public async Task<IActionResult> GetHistory(Guid orderId, CancellationToken ct)
        => Payload(await _orders.GetHistoryAsync(orderId, ct));

    /// <summary>
    /// Places an order on behalf of a table, from a waiter's device.
    /// <para>
    /// Requires an idempotency key: a waiter tapping twice on a weak connection
    /// must not put two orders into the kitchen.
    /// </para>
    /// </summary>
    [HttpPost("sessions/{tableSessionId:guid}")]
    [Authorize(Policy = Permissions.OrdersCreate)]
    [RequireIdempotencyKey]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status400BadRequest)]
    public async Task<IActionResult> PlaceOrder(
        Guid tableSessionId,
        [FromBody] PlaceOrderRequest request,
        CancellationToken ct)
        => Ok(await _orders.PlaceOrderAsync(tableSessionId, request, OrderSource.Waiter, ct));

    /// <summary>Accepts a guest order that was held for review.</summary>
    [HttpPost("{orderId:guid}/confirm")]
    [Authorize(Policy = Permissions.OrdersUpdate)]
    [RequireIdempotencyKey]
    public async Task<IActionResult> Confirm(Guid orderId, CancellationToken ct)
        => Ok(await _orders.ConfirmAsync(orderId, ct));

    /// <summary>Records that the food reached the guest, and who carried it.</summary>
    [HttpPost("{orderId:guid}/serve")]
    [Authorize(Policy = Permissions.OrdersServe)]
    public async Task<IActionResult> Serve(Guid orderId, CancellationToken ct)
        => Ok(await _orders.ServeAsync(orderId, ct));

    [HttpPost("{orderId:guid}/cancel")]
    [Authorize(Policy = Permissions.OrdersCancel)]
    public async Task<IActionResult> Cancel(
        Guid orderId,
        [FromBody] CancelOrderRequest request,
        CancellationToken ct)
        => Ok(await _orders.CancelAsync(orderId, request, ct));

    /// <summary>Applies a discount, capped by the venue's configured maximum.</summary>
    [HttpPost("{orderId:guid}/discount")]
    [Authorize(Policy = Permissions.PaymentsDiscount)]
    public async Task<IActionResult> ApplyDiscount(
        Guid orderId,
        [FromBody] ApplyDiscountRequest request,
        CancellationToken ct)
        => Ok(await _orders.ApplyDiscountAsync(orderId, request, ct));
}

[Route("api/v1/menu")]
[Authorize]
public sealed class MenuController : ApiControllerBase
{
    private readonly IMenuService _menu;

    public MenuController(IMenuService menu) => _menu = menu;

    [HttpGet]
    [Authorize(Policy = Permissions.MenuView)]
    public async Task<IActionResult> GetMenu(CancellationToken ct)
        => Payload(await _menu.GetMenuAsync(availableOnly: false, ct));

    [HttpGet("categories")]
    [Authorize(Policy = Permissions.MenuView)]
    public async Task<IActionResult> GetCategories(CancellationToken ct)
        => Payload(await _menu.GetCategoriesAsync(ct));

    [HttpGet("products")]
    [Authorize(Policy = Permissions.MenuView)]
    public async Task<IActionResult> GetProducts([FromQuery] Guid? categoryId, CancellationToken ct)
        => Payload(await _menu.GetProductsAsync(categoryId, ct));

    [HttpPost("products")]
    [Authorize(Policy = Permissions.MenuManage)]
    public async Task<IActionResult> CreateProduct(
        [FromBody] Application.Menu.Dtos.CreateProductRequest request,
        CancellationToken ct)
        => Ok(await _menu.CreateProductAsync(request, ct));

    /// <summary>
    /// The "we've run out" switch. One tap, mid-service, and the product
    /// disappears from every guest's phone.
    /// </summary>
    [HttpPatch("products/{productId:guid}/availability")]
    [Authorize(Policy = Permissions.MenuManage)]
    public async Task<IActionResult> SetAvailability(
        Guid productId,
        [FromBody] Application.Menu.Dtos.UpdateAvailabilityRequest request,
        CancellationToken ct)
        => Ok(await _menu.SetAvailabilityAsync(productId, request.IsAvailable, ct));

    [HttpGet("stations")]
    [Authorize(Policy = Permissions.MenuView)]
    public async Task<IActionResult> GetStations(CancellationToken ct)
        => Payload(await _menu.GetStationsAsync(ct));
}
