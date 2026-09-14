using Microsoft.AspNetCore.Identity;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;
using STED.RestaurantOS.Application.Security;
using STED.RestaurantOS.Infrastructure.Identity;

namespace STED.RestaurantOS.Infrastructure.Persistence.Seed;

/// <summary>
/// Brings the permission catalogue and the built-in roles up to date.
/// <para>
/// Idempotent by design: it is safe to run on every deployment, and it is the
/// mechanism by which a permission added in code reaches an existing database.
/// It only ever adds — it never removes a permission an operator granted by
/// hand.
/// </para>
/// </summary>
public sealed class IdentitySeeder
{
    private readonly AppDbContext _db;
    private readonly RoleManager<ApplicationRole> _roles;
    private readonly ILogger<IdentitySeeder> _logger;

    public IdentitySeeder(
        AppDbContext db,
        RoleManager<ApplicationRole> roles,
        ILogger<IdentitySeeder> logger)
    {
        _db = db;
        _roles = roles;
        _logger = logger;
    }

    public async Task SeedAsync(CancellationToken cancellationToken)
    {
        await SeedPermissionsAsync(cancellationToken);
        await SeedRolesAsync();
        await SeedRolePermissionsAsync(cancellationToken);
    }

    private async Task SeedPermissionsAsync(CancellationToken cancellationToken)
    {
        var existing = await _db.Permissions
            .Select(p => p.Code)
            .ToListAsync(cancellationToken);

        var missing = Permissions.All
            .Where(code => !existing.Contains(code, StringComparer.Ordinal))
            .Select(code => new Permission(code, Permissions.ModuleOf(code)))
            .ToList();

        if (missing.Count == 0)
        {
            return;
        }

        _db.Permissions.AddRange(missing);
        await _db.SaveChangesAsync(cancellationToken);

        _logger.LogInformation("Seeded {Count} new permission(s).", missing.Count);
    }

    private async Task SeedRolesAsync()
    {
        foreach (var roleName in RoleNames.All)
        {
            if (await _roles.RoleExistsAsync(roleName))
            {
                continue;
            }

            var outcome = await _roles.CreateAsync(new ApplicationRole(roleName));

            if (!outcome.Succeeded)
            {
                throw new InvalidOperationException(
                    $"Could not create role '{roleName}': " +
                    string.Join(" ", outcome.Errors.Select(e => e.Description)));
            }
        }
    }

    private async Task SeedRolePermissionsAsync(CancellationToken cancellationToken)
    {
        var permissionsByCode = await _db.Permissions
            .ToDictionaryAsync(p => p.Code, p => p.Id, StringComparer.Ordinal, cancellationToken);

        var roles = await _db.Roles
            .Where(r => r.RestaurantId == null)
            .ToListAsync(cancellationToken);

        var existing = await _db.RolePermissions
            .Select(rp => new { rp.RoleId, rp.PermissionId })
            .ToListAsync(cancellationToken);

        var known = existing
            .Select(rp => (rp.RoleId, rp.PermissionId))
            .ToHashSet();

        var added = 0;

        foreach (var role in roles)
        {
            if (role.Name is null)
            {
                continue;
            }

            foreach (var code in RolePermissionDefaults.For(role.Name))
            {
                if (!permissionsByCode.TryGetValue(code, out var permissionId))
                {
                    continue;
                }

                if (known.Contains((role.Id, permissionId)))
                {
                    continue;
                }

                _db.RolePermissions.Add(new RolePermission(role.Id, permissionId));
                added++;
            }
        }

        if (added == 0)
        {
            return;
        }

        await _db.SaveChangesAsync(cancellationToken);
        _logger.LogInformation("Granted {Count} new role permission(s).", added);
    }
}
