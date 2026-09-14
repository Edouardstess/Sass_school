using System.Linq.Expressions;
using System.Reflection;
using Microsoft.EntityFrameworkCore;
using STED.RestaurantOS.Domain.Auditing;
using STED.RestaurantOS.Domain.Billing;
using STED.RestaurantOS.Domain.Catalog;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Domain.Notifications;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.Preparation;
using STED.RestaurantOS.Domain.Restaurants;
using STED.RestaurantOS.Domain.Staff;
using STED.RestaurantOS.Infrastructure.Idempotency;

namespace STED.RestaurantOS.Infrastructure.Persistence;

/// <summary>
/// The single write model. Only aggregate roots get a DbSet: everything else is
/// reached through its root, which is what keeps the invariants enforceable.
/// </summary>
public sealed class AppDbContext : DbContext
{
    private readonly ITenantContext _tenant;

    public AppDbContext(DbContextOptions<AppDbContext> options, ITenantContext tenant)
        : base(options)
        => _tenant = tenant;

    public DbSet<Restaurant> Restaurants => Set<Restaurant>();

    public DbSet<StaffProfile> StaffProfiles => Set<StaffProfile>();

    public DbSet<TableZone> TableZones => Set<TableZone>();

    public DbSet<RestaurantTable> RestaurantTables => Set<RestaurantTable>();

    public DbSet<TableSession> TableSessions => Set<TableSession>();

    public DbSet<Station> Stations => Set<Station>();

    public DbSet<MenuCategory> MenuCategories => Set<MenuCategory>();

    public DbSet<Product> Products => Set<Product>();

    public DbSet<Order> Orders => Set<Order>();

    public DbSet<PreparationTicket> PreparationTickets => Set<PreparationTicket>();

    public DbSet<Payment> Payments => Set<Payment>();

    public DbSet<AuditLog> AuditLogs => Set<AuditLog>();

    public DbSet<Notification> Notifications => Set<Notification>();

    public DbSet<IdempotencyRecord> IdempotencyRecords => Set<IdempotencyRecord>();

    /// <summary>
    /// Read-only views onto entities owned by an aggregate. Exposed for queries
    /// and reports, which legitimately read across aggregates; writes still go
    /// through the root.
    /// </summary>
    public IQueryable<ServiceAssignment> ServiceAssignments => Set<ServiceAssignment>().AsNoTracking();

    public IQueryable<ServiceAssignmentHistory> ServiceAssignmentHistory
        => Set<ServiceAssignmentHistory>().AsNoTracking();

    public IQueryable<OrderItem> OrderItems => Set<OrderItem>().AsNoTracking();

    public IQueryable<OrderStatusHistory> OrderStatusHistory => Set<OrderStatusHistory>().AsNoTracking();

    /// <summary>
    /// The restaurant every query is pinned to. <see cref="Guid.Empty"/> when
    /// there is no authenticated restaurant, which matches no row — the safe
    /// default is to show nothing, never everything.
    /// </summary>
    public Guid CurrentRestaurantId => _tenant.RestaurantId ?? Guid.Empty;

    /// <summary>
    /// True only for platform administration. Everything else is filtered, with
    /// no way to opt out by accident.
    /// </summary>
    public bool TenantFilterDisabled => _tenant.IsPlatformAdministrator;

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        base.OnModelCreating(modelBuilder);

        modelBuilder.ApplyConfigurationsFromAssembly(Assembly.GetExecutingAssembly());

        ApplyTenantQueryFilters(modelBuilder);
        ApplyTimestampConventions(modelBuilder);
    }

    /// <summary>
    /// Level 2 of tenant isolation: every entity carrying a RestaurantId is
    /// filtered automatically. A developer cannot forget the WHERE clause,
    /// because they never write it.
    /// </summary>
    private void ApplyTenantQueryFilters(ModelBuilder modelBuilder)
    {
        foreach (var entityType in modelBuilder.Model.GetEntityTypes())
        {
            if (entityType.IsOwned() || !typeof(ITenantEntity).IsAssignableFrom(entityType.ClrType))
            {
                continue;
            }

            var parameter = Expression.Parameter(entityType.ClrType, "e");

            var entityRestaurantId = Expression.Property(
                parameter,
                nameof(ITenantEntity.RestaurantId));

            var contextConstant = Expression.Constant(this);

            var currentRestaurantId = Expression.Property(
                contextConstant,
                nameof(CurrentRestaurantId));

            var filterDisabled = Expression.Property(
                contextConstant,
                nameof(TenantFilterDisabled));

            var body = Expression.OrElse(
                filterDisabled,
                Expression.Equal(entityRestaurantId, currentRestaurantId));

            modelBuilder.Entity(entityType.ClrType)
                .HasQueryFilter(Expression.Lambda(body, parameter));
        }
    }

    /// <summary>
    /// Every instant is stored as <c>datetimeoffset</c>, in UTC. Local dates in a
    /// database are ambiguous twice a year and unrecoverable afterwards;
    /// conversion to the venue's timezone happens at display time only.
    /// </summary>
    private static void ApplyTimestampConventions(ModelBuilder modelBuilder)
    {
        foreach (var entityType in modelBuilder.Model.GetEntityTypes())
        {
            foreach (var property in entityType.GetProperties())
            {
                if (property.ClrType == typeof(DateTimeOffset) || property.ClrType == typeof(DateTimeOffset?))
                {
                    property.SetColumnType("datetimeoffset(3)");
                }
            }
        }
    }
}
