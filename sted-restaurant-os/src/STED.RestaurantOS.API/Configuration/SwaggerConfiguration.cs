using Microsoft.OpenApi.Models;

namespace STED.RestaurantOS.API.Configuration;

public static class SwaggerConfiguration
{
    public static IServiceCollection AddApiDocumentation(this IServiceCollection services)
    {
        services.AddEndpointsApiExplorer();

        services.AddSwaggerGen(options =>
        {
            options.SwaggerDoc("v1", new OpenApiInfo
            {
                Title = "STED Restaurant OS API",
                Version = "v1",
                Description =
                    "Operational API for restaurants, bars and lounges.\n\n" +
                    "Two kinds of bearer token exist. A **staff token** carries roles and " +
                    "permissions. A **guest token**, issued by scanning a table's QR code, " +
                    "carries only the session it belongs to and can never satisfy a staff " +
                    "policy.\n\n" +
                    "Endpoints that create orders or payments require an `Idempotency-Key` " +
                    "header: repeating a request with the same key returns the original " +
                    "response instead of charging twice.",
            });

            options.AddSecurityDefinition("Bearer", new OpenApiSecurityScheme
            {
                Name = "Authorization",
                Type = SecuritySchemeType.Http,
                Scheme = "bearer",
                BearerFormat = "JWT",
                In = ParameterLocation.Header,
                Description = "Paste the access token returned by /api/v1/auth/login.",
            });

            options.AddSecurityRequirement(new OpenApiSecurityRequirement
            {
                {
                    new OpenApiSecurityScheme
                    {
                        Reference = new OpenApiReference
                        {
                            Type = ReferenceType.SecurityScheme,
                            Id = "Bearer",
                        },
                    },
                    Array.Empty<string>()
                },
            });

            var xml = Path.Combine(AppContext.BaseDirectory, "STED.RestaurantOS.API.xml");

            if (File.Exists(xml))
            {
                options.IncludeXmlComments(xml);
            }
        });

        return services;
    }
}
