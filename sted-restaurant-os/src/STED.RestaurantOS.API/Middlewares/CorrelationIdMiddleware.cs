using STED.RestaurantOS.API.Tenancy;

namespace STED.RestaurantOS.API.Middlewares;

/// <summary>
/// Gives every request an identity that survives into the logs, the audit trail
/// and the response body.
/// <para>
/// When a guest says "I was charged twice", the support answer is one query:
/// <c>WHERE CorrelationId = '...'</c> returns the entire story of that request.
/// </para>
/// </summary>
public sealed class CorrelationIdMiddleware
{
    public const string HeaderName = "X-Correlation-Id";

    private readonly RequestDelegate _next;

    public CorrelationIdMiddleware(RequestDelegate next) => _next = next;

    public async Task InvokeAsync(HttpContext context)
    {
        var correlationId = context.Request.Headers[HeaderName].FirstOrDefault();

        if (string.IsNullOrWhiteSpace(correlationId) || correlationId.Length > 60)
        {
            correlationId = context.TraceIdentifier;
        }

        context.Items[HttpTenantContext.CorrelationIdKey] = correlationId;

        context.Response.OnStarting(() =>
        {
            context.Response.Headers[HeaderName] = correlationId;
            return Task.CompletedTask;
        });

        await _next(context);
    }
}
