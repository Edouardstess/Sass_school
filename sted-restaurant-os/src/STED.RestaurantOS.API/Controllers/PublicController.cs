using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.RateLimiting;
using STED.RestaurantOS.API.Configuration;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.API.Filters;
using STED.RestaurantOS.API.Tenancy;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Ordering.Dtos;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.API.Controllers;

/// <summary>
/// Everything a diner's phone talks to.
/// <para>
/// Only the first endpoint is anonymous. After scanning, the guest holds a token
/// bound to one table session, and every route below refuses to act on any other
/// session — the session id is taken from the token, never from the URL, so a
/// guest cannot read the next table's bill by changing a number.
/// </para>
/// </summary>
[Route("api/v1/public")]
public sealed class PublicController : ApiControllerBase
{
    private readonly IGuestSessionService _guests;
    private readonly IMenuService _menu;
    private readonly IOrderService _orders;
    private readonly HttpTenantContext _tenant;

    public PublicController(
        IGuestSessionService guests,
        IMenuService menu,
        IOrderService orders,
        HttpTenantContext tenant)
    {
        _guests = guests;
        _menu = menu;
        _orders = orders;
        _tenant = tenant;
    }

    /// <summary>
    /// Scanning the code on the table. Returns the venue, the table, and a guest
    /// token scoped to this party's session.
    /// </summary>
    [HttpGet("qr/{token}")]
    [AllowAnonymous]
    [EnableRateLimiting(RateLimitPolicies.Public)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status404NotFound)]
    public async Task<IActionResult> ResolveQrCode(string token, CancellationToken ct)
        => Ok(await _guests.ResolveAsync(token, ct));

    /// <summary>
    /// The menu, filtered to what the kitchen can actually make right now.
    /// A sold-out dish is absent, not greyed out.
    /// </summary>
    [HttpGet("menu")]
    [Authorize]
    [EnableRateLimiting(RateLimitPolicies.Public)]
    public async Task<IActionResult> GetMenu(CancellationToken ct)
    {
        if (_tenant.GuestTableSessionId is null)
        {
            return GuestOnly();
        }

        return Payload(await _menu.GetMenuAsync(availableOnly: true, ct));
    }

    /// <summary>
    /// Prices a basket without creating anything.
    /// <para>
    /// The phone shows this total, not one it computed itself. Anything the
    /// client says about money is ignored — this is the only figure that counts.
    /// </para>
    /// </summary>
    [HttpPost("cart/price")]
    [Authorize]
    [EnableRateLimiting(RateLimitPolicies.Public)]
    public async Task<IActionResult> PriceCart(
        [FromBody] PlaceOrderRequest request,
        CancellationToken ct)
    {
        if (_tenant.GuestTableSessionId is null)
        {
            return GuestOnly();
        }

        return Ok(await _orders.QuoteAsync(request.Lines, ct));
    }

    /// <summary>
    /// Sends the order.
    /// <para>
    /// The session comes from the token. The idempotency key means a guest who
    /// taps twice on a weak connection gets one order, not two — which is the
    /// single most expensive mistake this endpoint could make.
    /// </para>
    /// </summary>
    [HttpPost("orders")]
    [Authorize]
    [EnableRateLimiting(RateLimitPolicies.GuestOrdering)]
    [RequireIdempotencyKey]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status409Conflict)]
    public async Task<IActionResult> PlaceOrder(
        [FromBody] PlaceOrderRequest request,
        CancellationToken ct)
    {
        if (_tenant.GuestTableSessionId is not { } sessionId)
        {
            return GuestOnly();
        }

        return Ok(await _orders.PlaceOrderAsync(sessionId, request, OrderSource.Qr, ct));
    }

    /// <summary>Tracks one of this party's own orders.</summary>
    [HttpGet("orders/{orderId:guid}")]
    [Authorize]
    public async Task<IActionResult> TrackOrder(Guid orderId, CancellationToken ct)
    {
        if (_tenant.GuestTableSessionId is not { } sessionId)
        {
            return GuestOnly();
        }

        var order = await _orders.GetOrderAsync(orderId, ct);

        // Belt and braces: even holding a valid guest token, you only see orders
        // from your own session.
        if (order.IsSuccess && order.Value!.TableSessionId != sessionId)
        {
            return NotFound(ApiResponse.Fail(
                "This order does not exist.", ErrorCodes.OrderNotFound, CorrelationId));
        }

        return Ok(order);
    }

    /// <summary>This party's running tab.</summary>
    [HttpGet("session")]
    [Authorize]
    public async Task<IActionResult> GetMySession(CancellationToken ct)
    {
        if (_tenant.GuestTableSessionId is not { } sessionId)
        {
            return GuestOnly();
        }

        var orders = await _orders.SearchAsync(
            new OrderFilter { TableSessionId = sessionId },
            new Shared.Paging.PagedRequest { PageSize = 50 },
            ct);

        return Payload(new
        {
            TableSessionId = sessionId,
            TableId = _tenant.GuestTableId,
            Orders = orders.Items,
            Total = orders.Items
                .Where(o => o.Status != OrderStatus.Cancelled)
                .Sum(o => o.Total),
        });
    }

    [HttpPost("session/call-waiter")]
    [Authorize]
    [EnableRateLimiting(RateLimitPolicies.GuestOrdering)]
    public async Task<IActionResult> CallWaiter(CancellationToken ct)
    {
        if (_tenant.GuestTableSessionId is not { } sessionId)
        {
            return GuestOnly();
        }

        return Ok(await _guests.CallWaiterAsync(sessionId, ct));
    }

    [HttpPost("session/request-bill")]
    [Authorize]
    [EnableRateLimiting(RateLimitPolicies.GuestOrdering)]
    public async Task<IActionResult> RequestBill(CancellationToken ct)
    {
        if (_tenant.GuestTableSessionId is not { } sessionId)
        {
            return GuestOnly();
        }

        return Ok(await _guests.RequestBillAsync(sessionId, ct));
    }

    private IActionResult GuestOnly()
        => Unauthorized(ApiResponse.Fail(
            "This endpoint needs a guest token. Scan the code on your table.",
            ErrorCodes.Unauthorized,
            CorrelationId));
}
