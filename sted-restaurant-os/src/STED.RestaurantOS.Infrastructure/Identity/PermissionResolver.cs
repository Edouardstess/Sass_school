using Microsoft.EntityFrameworkCore;
using STED.RestaurantOS.Infrastructure.Persistence;

namespace STED.RestaurantOS.Infrastructure.Identity;

public interface IPermissionResolver
{
    Task<IReadOnlyList<string>> ResolveAsync(Guid userId, CancellationToken cancellationToken);
}

/// <summary>
/// Works out everything a person may attempt: what their roles grant, plus
/// individual grants, minus individual revocations.
/// <para>
/// Revocations are applied last and win. Taking a permission away from one
/// person must not be quietly undone by a role that also hands it out — that is
/// the direction where a mistake is expensive.
/// </para>
/// </summary>
public sealed class PermissionResolver : IPermissionResolver
{
    private readonly AppDbContext _db;

    public PermissionResolver(AppDbContext db) => _db = db;

    public async Task<IReadOnlyList<string>> ResolveAsync(Guid userId, CancellationToken cancellationToken)
    {
        var roleIds = await _db.UserRoles
            .Where(ur => ur.UserId == userId)
            .Select(ur => ur.RoleId)
            .ToListAsync(cancellationToken);

        var fromRoles = await _db.RolePermissions
            .Where(rp => roleIds.Contains(rp.RoleId))
            .Join(_db.Permissions, rp => rp.PermissionId, p => p.Id, (_, p) => p.Code)
            .ToListAsync(cancellationToken);

        var overrides = await _db.UserPermissionOverrides
            .Where(o => o.UserId == userId)
            .Join(
                _db.Permissions,
                o => o.PermissionId,
                p => p.Id,
                (o, p) => new { p.Code, o.IsGranted })
            .ToListAsync(cancellationToken);

        var effective = new HashSet<string>(fromRoles, StringComparer.Ordinal);

        foreach (var granted in overrides.Where(o => o.IsGranted))
        {
            effective.Add(granted.Code);
        }

        foreach (var revoked in overrides.Where(o => !o.IsGranted))
        {
            effective.Remove(revoked.Code);
        }

        return effective.OrderBy(code => code, StringComparer.Ordinal).ToList();
    }
}
