using System.Text.Json;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.API.Tenancy;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Infrastructure.Tenancy;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.API.Middlewares;

/// <summary>
/// Turns every exception into the same response envelope.
/// <para>
/// Business refusals keep their code and their message — the frontend needs
/// both. Anything unexpected is logged in full and answered with a generic
/// message: a stack trace in a production response is a map of the system for
/// anyone who asks.
/// </para>
/// </summary>
public sealed class ExceptionHandlingMiddleware
{
    private readonly RequestDelegate _next;
    private readonly ILogger<ExceptionHandlingMiddleware> _logger;
    private readonly IHostEnvironment _environment;

    public ExceptionHandlingMiddleware(
        RequestDelegate next,
        ILogger<ExceptionHandlingMiddleware> logger,
        IHostEnvironment environment)
    {
        _next = next;
        _logger = logger;
        _environment = environment;
    }

    public async Task InvokeAsync(HttpContext context)
    {
        try
        {
            await _next(context);
        }
        catch (Exception exception)
        {
            await WriteAsync(context, exception);
        }
    }

    private async Task WriteAsync(HttpContext context, Exception exception)
    {
        var correlationId = context.Items[HttpTenantContext.CorrelationIdKey] as string
                            ?? context.TraceIdentifier;

        var (status, code, message) = Map(exception);

        if (status >= StatusCodes.Status500InternalServerError)
        {
            _logger.LogError(
                exception,
                "Unhandled exception on {Method} {Path}. Correlation {CorrelationId}.",
                context.Request.Method,
                context.Request.Path,
                correlationId);
        }
        else
        {
            _logger.LogInformation(
                "Request refused on {Method} {Path}: {Code}. Correlation {CorrelationId}.",
                context.Request.Method,
                context.Request.Path,
                code,
                correlationId);
        }

        if (context.Response.HasStarted)
        {
            return;
        }

        context.Response.Clear();
        context.Response.StatusCode = status;
        context.Response.ContentType = "application/json";

        var body = ApiResponse.Fail(
            message,
            code,
            correlationId,
            _environment.IsDevelopment() && status >= 500 ? exception.ToString() : null);

        await context.Response.WriteAsync(
            JsonSerializer.Serialize(body, JsonDefaults.Options),
            context.RequestAborted);
    }

    private static (int Status, string Code, string Message) Map(Exception exception) => exception switch
    {
        // Crossing a restaurant boundary answers 404, never 403: confirming that
        // the other venue's record exists is itself a disclosure.
        TenantViolationException => (
            StatusCodes.Status404NotFound,
            ErrorCodes.NotFound,
            "The requested resource does not exist."),

        DomainValidationException validation => (
            StatusCodes.Status400BadRequest,
            validation.Code,
            validation.Message),

        BusinessRuleViolationException rule => (
            MapBusinessStatus(rule.Code),
            rule.Code,
            rule.Message),

        UnauthorizedAccessException => (
            StatusCodes.Status403Forbidden,
            ErrorCodes.Forbidden,
            "You are not allowed to do that."),

        // 499 is nginx's "client closed request"; ASP.NET has no constant for it.
        OperationCanceledException => (
            499,
            "REQUEST_CANCELLED",
            "The request was cancelled."),

        _ => (
            StatusCodes.Status500InternalServerError,
            ErrorCodes.InternalError,
            "Something went wrong. Please try again."),
    };

    private static int MapBusinessStatus(string code) => code switch
    {
        ErrorCodes.NotFound or ErrorCodes.OrderNotFound or ErrorCodes.QrCodeInvalid
            or ErrorCodes.TableNotFound => StatusCodes.Status404NotFound,

        ErrorCodes.Forbidden or ErrorCodes.NotTheAssignedWaiter => StatusCodes.Status403Forbidden,

        ErrorCodes.ValidationError or ErrorCodes.IdempotencyKeyRequired
            or ErrorCodes.SameWaiterTransfer => StatusCodes.Status400BadRequest,

        _ => StatusCodes.Status409Conflict,
    };
}
