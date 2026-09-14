using System.Text.Json.Serialization;
using FluentValidation;
using Serilog;
using STED.RestaurantOS.API.Configuration;
using STED.RestaurantOS.API.Contracts;
using STED.RestaurantOS.API.Middlewares;
using STED.RestaurantOS.API.Tenancy;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Infrastructure;
using STED.RestaurantOS.Infrastructure.Persistence.Seed;

var builder = WebApplication.CreateBuilder(args);

// ---------------------------------------------------------------------------
// Logging. Structured from the first line: every entry carries the correlation
// id, the user and the restaurant, so one incident is one query.
// ---------------------------------------------------------------------------
builder.Host.UseSerilog((context, configuration) => configuration
    .ReadFrom.Configuration(context.Configuration)
    .Enrich.FromLogContext()
    .WriteTo.Console());

// ---------------------------------------------------------------------------
// Configuration and secrets. The connection string and the signing key come
// from the environment; neither has a default, so a misconfigured deployment
// fails at startup instead of running with something insecure.
// ---------------------------------------------------------------------------
var connectionString = builder.Configuration.GetConnectionString("Default")
    ?? throw new InvalidOperationException(
        "ConnectionStrings:Default is not configured. Provide it through the environment.");

// ---------------------------------------------------------------------------
// Services
// ---------------------------------------------------------------------------
builder.Services.AddHttpContextAccessor();

// The tenant is resolved from the authenticated principal only. Registered
// before persistence, because the DbContext's query filters depend on it.
builder.Services.AddScoped<ITenantContext, HttpTenantContext>();

builder.Services.AddPersistence(connectionString);
builder.Services.AddIdentityAndAuthentication(builder.Configuration);
builder.Services.AddApiRateLimiting();
builder.Services.AddApiCors(builder.Configuration);
builder.Services.AddApiDocumentation();

builder.Services.AddValidatorsFromAssemblyContaining<IAuthenticationService>();

builder.Services.AddControllers()
    .AddJsonOptions(options =>
    {
        options.JsonSerializerOptions.PropertyNamingPolicy = JsonDefaults.Options.PropertyNamingPolicy;
        options.JsonSerializerOptions.DefaultIgnoreCondition = JsonDefaults.Options.DefaultIgnoreCondition;
        options.JsonSerializerOptions.Converters.Add(new JsonStringEnumConverter());
    });

builder.Services.AddHealthChecks()
    .AddSqlServer(connectionString, name: "sql", tags: ["ready"]);

var app = builder.Build();

// ---------------------------------------------------------------------------
// Pipeline. The order matters: correlation first so everything downstream can
// log it, exception handling next so it wraps everything after it.
// ---------------------------------------------------------------------------
app.UseMiddleware<CorrelationIdMiddleware>();
app.UseMiddleware<ExceptionHandlingMiddleware>();

app.UseSerilogRequestLogging(options =>
{
    options.EnrichDiagnosticContext = (diagnostic, context) =>
    {
        diagnostic.Set("CorrelationId", context.Items[HttpTenantContext.CorrelationIdKey]);
        diagnostic.Set("UserId", context.User.FindFirst("sub")?.Value);
        diagnostic.Set("RestaurantId", context.User.FindFirst("restaurant_id")?.Value);
    };
});

if (app.Environment.IsDevelopment())
{
    // Swagger is a map of the system. It stays in development only.
    app.UseSwagger();
    app.UseSwaggerUI(options => options.SwaggerEndpoint("/swagger/v1/swagger.json", "STED Restaurant OS v1"));
}
else
{
    app.UseHsts();
}

app.UseHttpsRedirection();
app.UseCors(CorsConfiguration.PolicyName);
app.UseRateLimiter();

app.UseAuthentication();
app.UseAuthorization();

app.MapControllers();

app.MapHealthChecks("/health/live", new Microsoft.AspNetCore.Diagnostics.HealthChecks.HealthCheckOptions
{
    // Liveness answers "is the process alive", nothing more. If it checked the
    // database, a brief outage would make the orchestrator kill healthy pods.
    Predicate = _ => false,
});

app.MapHealthChecks("/health/ready", new Microsoft.AspNetCore.Diagnostics.HealthChecks.HealthCheckOptions
{
    Predicate = check => check.Tags.Contains("ready"),
});

// ---------------------------------------------------------------------------
// Seeding. Permissions and built-in roles only — never schema migrations: two
// instances migrating in parallel corrupt a database, so that is a deployment
// step, not a startup step.
// ---------------------------------------------------------------------------
using (var scope = app.Services.CreateScope())
{
    var seeder = scope.ServiceProvider.GetRequiredService<IdentitySeeder>();
    await seeder.SeedAsync(CancellationToken.None);
}

await app.RunAsync();

/// <summary>Exposed so the integration tests can spin the real application up.</summary>
public partial class Program;
