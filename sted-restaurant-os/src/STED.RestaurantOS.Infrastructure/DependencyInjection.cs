using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.DependencyInjection;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Infrastructure.Persistence;
using STED.RestaurantOS.Infrastructure.Persistence.Interceptors;

namespace STED.RestaurantOS.Infrastructure;

public static class DependencyInjection
{
    /// <summary>
    /// Registers the persistence layer.
    /// <para>
    /// <c>ITenantContext</c> is deliberately NOT registered here: it is supplied
    /// by the host (the API registers an HTTP-scoped implementation reading the
    /// authenticated principal; tests and jobs supply their own). Infrastructure
    /// must never be able to invent a tenant for itself.
    /// </para>
    /// </summary>
    public static IServiceCollection AddPersistence(
        this IServiceCollection services,
        string connectionString)
    {
        services.AddSingleton<IDateTimeProvider, SystemDateTimeProvider>();
        services.AddScoped<AuditableEntityInterceptor>();
        services.AddScoped<TenantGuardInterceptor>();

        services.AddDbContext<AppDbContext>((provider, options) =>
        {
            options.UseSqlServer(connectionString, sql =>
            {
                sql.MigrationsAssembly(typeof(AppDbContext).Assembly.FullName);

                // Transient faults only (deadlock victim, timeout). Business
                // conflicts are never retried blindly: a repeated payment is
                // exactly what must not happen.
                sql.EnableRetryOnFailure(
                    maxRetryCount: 3,
                    maxRetryDelay: TimeSpan.FromSeconds(5),
                    errorNumbersToAdd: null);
            });

            options.AddInterceptors(
                provider.GetRequiredService<AuditableEntityInterceptor>(),
                provider.GetRequiredService<TenantGuardInterceptor>());
        });

        return services;
    }
}
