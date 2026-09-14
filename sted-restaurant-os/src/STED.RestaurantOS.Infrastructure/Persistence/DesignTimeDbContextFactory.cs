using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Design;
using STED.RestaurantOS.Infrastructure.Tenancy;

namespace STED.RestaurantOS.Infrastructure.Persistence;

/// <summary>
/// Lets <c>dotnet ef migrations</c> build a context without starting the API.
/// <para>
/// It uses the platform tenant context, because generating a migration is a
/// cross-tenant, schema-level operation — and because there is no authenticated
/// request to derive a restaurant from. The connection string comes from
/// <c>STED_CONNECTION_STRING</c>; nothing here ever reaches production.
/// </para>
/// </summary>
public sealed class DesignTimeDbContextFactory : IDesignTimeDbContextFactory<AppDbContext>
{
    public AppDbContext CreateDbContext(string[] args)
    {
        var connectionString = Environment.GetEnvironmentVariable("STED_CONNECTION_STRING")
            ?? "Server=localhost,1433;Database=StedRestaurantOs;User Id=sa;Password=Local_Dev_Password_1;TrustServerCertificate=True";

        var options = new DbContextOptionsBuilder<AppDbContext>()
            .UseSqlServer(connectionString, sql => sql.MigrationsAssembly(typeof(AppDbContext).Assembly.FullName))
            .Options;

        return new AppDbContext(options, SystemTenantContext.Platform());
    }
}
