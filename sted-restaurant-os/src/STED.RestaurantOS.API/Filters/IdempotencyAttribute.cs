using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.Mvc.Filters;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.API.Filters;

/// <summary>
/// Requires an <c>Idempotency-Key</c> header on the endpoints where a repeat
/// would cost real money or real food.
/// <para>
/// This filter only enforces the header's presence and shape. The actual
/// replay logic lives in <c>IIdempotencyStore</c> and runs inside the business
/// transaction, because that is the only place where "the operation happened"
/// and "we recorded that it happened" can be made atomic.
/// </para>
/// </summary>
[AttributeUsage(AttributeTargets.Method)]
public sealed class RequireIdempotencyKeyAttribute : ActionFilterAttribute
{
    public const string HeaderName = "Idempotency-Key";

    public override void OnActionExecuting(ActionExecutingContext context)
    {
        var key = context.HttpContext.Request.Headers[HeaderName].FirstOrDefault();

        if (string.IsNullOrWhiteSpace(key) || key.Length > 80)
        {
            context.Result = new BadRequestObjectResult(ApiResponse.Fail(
                $"This endpoint requires an '{HeaderName}' header of at most 80 characters. " +
                "Generate one per user action and reuse it when retrying.",
                ErrorCodes.IdempotencyKeyRequired,
                context.HttpContext.TraceIdentifier));

            return;
        }

        context.HttpContext.Items[HeaderName] = key;
        base.OnActionExecuting(context);
    }
}
