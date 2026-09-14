using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.API.Filters;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Billing.Dtos;
using STED.RestaurantOS.Application.Security;

namespace STED.RestaurantOS.API.Controllers;

[Route("api/v1/cashier")]
[Authorize]
public sealed class CashierController : ApiControllerBase
{
    private readonly IBillingService _billing;

    public CashierController(IBillingService billing) => _billing = billing;

    /// <summary>Tables with something left to pay, oldest first.</summary>
    [HttpGet("pending-sessions")]
    [Authorize(Policy = Permissions.PaymentsView)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    public async Task<IActionResult> GetPendingSessions(CancellationToken ct)
        => Payload(await _billing.GetPendingSessionsAsync(ct));

    /// <summary>The full bill for a table: every order, every line, what is left.</summary>
    [HttpGet("sessions/{tableSessionId:guid}/bill")]
    [Authorize(Policy = Permissions.PaymentsView)]
    public async Task<IActionResult> GetBill(Guid tableSessionId, CancellationToken ct)
        => Ok(await _billing.GetBillAsync(tableSessionId, ct));
}

[Route("api/v1/payments")]
[Authorize]
public sealed class PaymentsController : ApiControllerBase
{
    private readonly IBillingService _billing;

    public PaymentsController(IBillingService billing) => _billing = billing;

    /// <summary>
    /// Takes a payment.
    /// <para>
    /// The idempotency key is mandatory and the single most important header in
    /// this API: a cashier tapping twice on a slow connection must never charge
    /// a guest twice. Repeating a request with the same key returns the original
    /// payment rather than creating a second one.
    /// </para>
    /// </summary>
    [HttpPost]
    [Authorize(Policy = Permissions.PaymentsCreate)]
    [RequireIdempotencyKey]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status200OK)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status400BadRequest)]
    [ProducesResponseType(typeof(ApiResponse), StatusCodes.Status409Conflict)]
    public async Task<IActionResult> TakePayment(
        [FromBody] TakePaymentRequest request,
        CancellationToken ct)
    {
        var key = HttpContext.Items[RequireIdempotencyKeyAttribute.HeaderName] as string ?? string.Empty;

        return Ok(await _billing.TakePaymentAsync(request, key, ct));
    }

    /// <summary>Everything a printed receipt needs, already resolved.</summary>
    [HttpGet("{paymentId:guid}/receipt")]
    [Authorize(Policy = Permissions.PaymentsView)]
    public async Task<IActionResult> GetReceipt(Guid paymentId, CancellationToken ct)
        => Ok(await _billing.GetReceiptAsync(paymentId, ct));

    /// <summary>
    /// Refunds a payment. The original row stays: a charge and its refund are
    /// two facts, not one correction.
    /// </summary>
    [HttpPost("{paymentId:guid}/refund")]
    [Authorize(Policy = Permissions.PaymentsDiscount)]
    public async Task<IActionResult> Refund(
        Guid paymentId,
        [FromBody] RefundRequest request,
        CancellationToken ct)
        => Ok(await _billing.RefundAsync(paymentId, request, ct));
}
