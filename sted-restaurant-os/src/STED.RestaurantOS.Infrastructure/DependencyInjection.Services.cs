using Microsoft.Extensions.DependencyInjection;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Infrastructure.Payments;
using STED.RestaurantOS.Infrastructure.Realtime;
using STED.RestaurantOS.Infrastructure.Services;

namespace STED.RestaurantOS.Infrastructure;

public static class ServiceRegistration
{
    /// <summary>
    /// Registers the application services and the real-time transport.
    /// <para>
    /// All scoped: each one holds a DbContext and a tenant context, and both are
    /// per-request by nature. A singleton here would pin one restaurant's tenant
    /// context for the lifetime of the process.
    /// </para>
    /// </summary>
    public static IServiceCollection AddApplicationServices(this IServiceCollection services)
    {
        services.AddScoped<NumberSequence>();

        services.AddScoped<IFloorService, FloorService>();
        services.AddScoped<IMenuService, MenuService>();
        services.AddScoped<IOrderService, OrderService>();
        services.AddScoped<IPreparationService, PreparationService>();
        services.AddScoped<IGuestSessionService, GuestSessionService>();
        services.AddScoped<IBillingService, BillingService>();
        services.AddScoped<IReportService, ReportService>();

        // Cash is the only method implemented. The others are modelled, not
        // simulated: adding MonCash means adding an IPaymentProvider here.
        services.AddSingleton<IPaymentProvider, CashPaymentProvider>();
        services.AddSingleton<IPaymentProviderResolver, PaymentProviderResolver>();

        services.AddScoped<IRealtimeNotifier, SignalRNotifier>();

        services.AddSignalR(options =>
        {
            // A kitchen tablet on venue wifi drops constantly. Detecting it
            // quickly is what makes the client reconnect and re-read, rather
            // than sitting in front of a frozen screen believing it.
            options.KeepAliveInterval = TimeSpan.FromSeconds(10);
            options.ClientTimeoutInterval = TimeSpan.FromSeconds(30);
            options.EnableDetailedErrors = false;
        });

        return services;
    }
}
