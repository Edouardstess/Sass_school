using System.Threading.RateLimiting;
using Microsoft.AspNetCore.RateLimiting;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.API.Configuration;

public static class RateLimitPolicies
{
    public const string Authentication = "auth";
    public const string Public = "public";
    public const string GuestOrdering = "guest-orders";
    public const string Staff = "staff";

    public static IServiceCollection AddApiRateLimiting(this IServiceCollection services)
    {
        services.AddRateLimiter(options =>
        {
            options.RejectionStatusCode = StatusCodes.Status429TooManyRequests;

            options.OnRejected = async (context, cancellationToken) =>
            {
                context.HttpContext.Response.ContentType = "application/json";

                await context.HttpContext.Response.WriteAsJsonAsync(
                    ApiResponse.Fail(
                        "Too many requests. Please slow down.",
                        ErrorCodes.RateLimitExceeded,
                        context.HttpContext.TraceIdentifier),
                    cancellationToken);
            };

            // Sign-in: slow enough that guessing is pointless, keyed by address
            // so one attacker cannot lock out a whole venue.
            options.AddPolicy(Authentication, context => RateLimitPartition.GetFixedWindowLimiter(
                context.Connection.RemoteIpAddress?.ToString() ?? "unknown",
                _ => new FixedWindowRateLimiterOptions
                {
                    PermitLimit = 5,
                    Window = TimeSpan.FromMinutes(1),
                    QueueLimit = 0,
                }));

            // Scanning a QR code: generous, because a table of six all scan at once.
            options.AddPolicy(Public, context => RateLimitPartition.GetFixedWindowLimiter(
                context.Connection.RemoteIpAddress?.ToString() ?? "unknown",
                _ => new FixedWindowRateLimiterOptions
                {
                    PermitLimit = 20,
                    Window = TimeSpan.FromMinutes(1),
                    QueueLimit = 0,
                }));

            // Placing an order: keyed by session, not by address — a whole
            // restaurant can share one NAT address.
            options.AddPolicy(GuestOrdering, context => RateLimitPartition.GetFixedWindowLimiter(
                context.User.FindFirst("session_id")?.Value
                    ?? context.Connection.RemoteIpAddress?.ToString()
                    ?? "unknown",
                _ => new FixedWindowRateLimiterOptions
                {
                    PermitLimit = 5,
                    Window = TimeSpan.FromMinutes(1),
                    QueueLimit = 0,
                }));

            // Staff: high, and per user. A KDS polls every fifteen seconds all day.
            options.AddPolicy(Staff, context => RateLimitPartition.GetFixedWindowLimiter(
                context.User.FindFirst("sub")?.Value
                    ?? context.Connection.RemoteIpAddress?.ToString()
                    ?? "unknown",
                _ => new FixedWindowRateLimiterOptions
                {
                    PermitLimit = 300,
                    Window = TimeSpan.FromMinutes(1),
                    QueueLimit = 0,
                }));
        });

        return services;
    }
}
