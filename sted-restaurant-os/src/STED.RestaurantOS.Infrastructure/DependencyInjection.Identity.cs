using Microsoft.AspNetCore.Authentication.JwtBearer;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Identity;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.IdentityModel.Tokens;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Infrastructure.Idempotency;
using STED.RestaurantOS.Infrastructure.Identity;
using STED.RestaurantOS.Infrastructure.Persistence;
using STED.RestaurantOS.Infrastructure.Persistence.Seed;

namespace STED.RestaurantOS.Infrastructure;

public static class IdentityRegistration
{
    public static IServiceCollection AddIdentityAndAuthentication(
        this IServiceCollection services,
        IConfiguration configuration)
    {
        services.Configure<JwtOptions>(configuration.GetSection(JwtOptions.SectionName));

        services.AddIdentityCore<ApplicationUser>(options =>
            {
                options.User.RequireUniqueEmail = true;

                options.Password.RequiredLength = 10;
                options.Password.RequireDigit = true;
                options.Password.RequireUppercase = true;
                options.Password.RequireLowercase = true;
                options.Password.RequireNonAlphanumeric = false;

                // Five wrong attempts, then a fifteen-minute pause. Slow enough
                // to make guessing pointless, short enough that a waiter who
                // fat-fingered their PIN is not locked out of a whole service.
                options.Lockout.MaxFailedAccessAttempts = 5;
                options.Lockout.DefaultLockoutTimeSpan = TimeSpan.FromMinutes(15);
                options.Lockout.AllowedForNewUsers = true;
            })
            .AddRoles<ApplicationRole>()
            .AddEntityFrameworkStores<AppDbContext>()
            .AddDefaultTokenProviders();

        var jwt = configuration.GetSection(JwtOptions.SectionName).Get<JwtOptions>() ?? new JwtOptions();

        services.AddAuthentication(JwtBearerDefaults.AuthenticationScheme)
            .AddJwtBearer(options =>
            {
                options.TokenValidationParameters = new TokenValidationParameters
                {
                    ValidateIssuer = true,
                    ValidIssuer = jwt.Issuer,
                    ValidateAudience = true,

                    // Staff and guest tokens are both accepted here; endpoints
                    // then separate them by policy. A guest token carries no
                    // permission claim, so it can never satisfy a staff policy.
                    ValidAudiences = [jwt.Audience, jwt.GuestAudience],

                    ValidateIssuerSigningKey = true,
                    IssuerSigningKey = new SymmetricSecurityKey(
                        System.Text.Encoding.UTF8.GetBytes(jwt.SigningKey)),

                    ValidateLifetime = true,

                    // No tolerance for a late token: the default five minutes is
                    // a third of an access token's whole life here.
                    ClockSkew = TimeSpan.Zero,
                };

                // SignalR cannot set an Authorization header on the WebSocket
                // handshake, so the token arrives in the query string instead.
                options.Events = new JwtBearerEvents
                {
                    OnMessageReceived = context =>
                    {
                        var accessToken = context.Request.Query["access_token"];
                        var path = context.HttpContext.Request.Path;

                        if (!string.IsNullOrEmpty(accessToken)
                            && path.StartsWithSegments("/hubs", StringComparison.Ordinal))
                        {
                            context.Token = accessToken;
                        }

                        return Task.CompletedTask;
                    },
                };
            });

        services.AddSingleton<IAuthorizationPolicyProvider, PermissionPolicyProvider>();
        services.AddSingleton<IAuthorizationHandler, PermissionAuthorizationHandler>();
        services.AddAuthorization();

        services.AddScoped<IJwtTokenService, JwtTokenService>();
        services.AddScoped<IPermissionResolver, PermissionResolver>();
        services.AddScoped<IAuthenticationService, AuthenticationService>();
        services.AddScoped<IUnitOfWork, UnitOfWork>();
        services.AddScoped<IIdempotencyStore, SqlIdempotencyStore>();
        services.AddSingleton<IQrTokenFactory, QrTokenFactory>();
        services.AddScoped<IdentitySeeder>();

        return services;
    }
}
