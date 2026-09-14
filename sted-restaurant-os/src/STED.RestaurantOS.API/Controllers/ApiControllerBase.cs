using Microsoft.AspNetCore.Mvc;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.API.Tenancy;
using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.API.Controllers;

/// <summary>
/// Shared plumbing: one place that turns a <see cref="Result{T}"/> into an HTTP
/// response, so no controller invents its own status-code mapping.
/// </summary>
[ApiController]
[Produces("application/json")]
public abstract class ApiControllerBase : ControllerBase
{
    protected string CorrelationId
        => HttpContext.Items[HttpTenantContext.CorrelationIdKey] as string ?? HttpContext.TraceIdentifier;

    protected IActionResult Ok<T>(Result<T> result)
        => result.IsSuccess
            ? base.Ok(ApiResponse.Ok(result.Value, CorrelationId))
            : Problem(result.Error!.Value);

    protected IActionResult Ok(Result result)
        => result.IsSuccess
            ? base.Ok(ApiResponse.Ok(null, CorrelationId))
            : Problem(result.Error!.Value);

    protected IActionResult Created<T>(Result<T> result, string location)
        => result.IsSuccess
            ? base.Created(location, ApiResponse.Ok(result.Value, CorrelationId))
            : Problem(result.Error!.Value);

    protected IActionResult Payload(object? data) => base.Ok(ApiResponse.Ok(data, CorrelationId));

    private IActionResult Problem(Error error)
        => StatusCode(
            StatusFor(error.Code),
            ApiResponse.Fail(error.Message, error.Code, CorrelationId));

    private static int StatusFor(string code) => code switch
    {
        ErrorCodes.ValidationError or ErrorCodes.IdempotencyKeyRequired
            or ErrorCodes.SameWaiterTransfer => StatusCodes.Status400BadRequest,

        ErrorCodes.Unauthorized => StatusCodes.Status401Unauthorized,

        ErrorCodes.Forbidden or ErrorCodes.NotTheAssignedWaiter => StatusCodes.Status403Forbidden,

        ErrorCodes.NotFound or ErrorCodes.OrderNotFound or ErrorCodes.TableNotFound
            or ErrorCodes.QrCodeInvalid => StatusCodes.Status404NotFound,

        ErrorCodes.RateLimitExceeded => StatusCodes.Status429TooManyRequests,

        ErrorCodes.InternalError => StatusCodes.Status500InternalServerError,

        _ => StatusCodes.Status409Conflict,
    };
}
