namespace STED.RestaurantOS.API.Configuration;

public static class CorsConfiguration
{
    public const string PolicyName = "sted-web";

    /// <summary>
    /// An explicit allow-list, read from configuration.
    /// <para>
    /// Credentials are allowed, which makes a wildcard origin both invalid and
    /// dangerous. <c>X-Correlation-Id</c> is exposed so the browser can read the
    /// request id back and show it in a support dialog.
    /// </para>
    /// </summary>
    public static IServiceCollection AddApiCors(this IServiceCollection services, IConfiguration configuration)
    {
        var origins = configuration.GetSection("Cors:AllowedOrigins").Get<string[]>() ?? [];

        services.AddCors(options => options.AddPolicy(PolicyName, policy =>
        {
            if (origins.Length == 0)
            {
                // No origins configured: allow nothing rather than everything.
                policy.WithOrigins("https://localhost");
                return;
            }

            policy.WithOrigins(origins)
                .AllowAnyHeader()
                .AllowAnyMethod()
                .AllowCredentials()
                .WithExposedHeaders("X-Correlation-Id");
        }));

        return services;
    }
}
