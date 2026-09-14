using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Domain.Restaurants;

/// <summary>
/// A single venue. This is the tenancy boundary for every operational query:
/// all business data hangs off exactly one RestaurantId.
/// <para>
/// <see cref="TenantId"/> is the paying SaaS customer and is deliberately
/// distinct from the restaurant's own identity, even though today the relation
/// is one to one. Adding it later would mean migrating every business table.
/// </para>
/// </summary>
public sealed class Restaurant : AuditableAggregateRoot
{
    public const string DefaultCurrency = "HTG";
    public const string DefaultTimezone = "America/Port-au-Prince";

    private Restaurant()
    {
    }

    private Restaurant(
        Guid id,
        Guid tenantId,
        string name,
        Slug slug,
        string currency,
        string timezone)
        : base(id)
    {
        TenantId = tenantId;
        Name = name;
        Slug = slug;
        Currency = currency;
        Timezone = timezone;
        IsActive = true;
        Settings = RestaurantSettings.CreateDefault(id);
    }

    public Guid TenantId { get; private set; }

    public string Name { get; private set; } = null!;

    public Slug Slug { get; private set; } = null!;

    public string? Address { get; private set; }

    public string? Phone { get; private set; }

    public string? Email { get; private set; }

    public string? LogoUrl { get; private set; }

    /// <summary>ISO 4217 code. Frozen once the restaurant has taken money.</summary>
    public string Currency { get; private set; } = DefaultCurrency;

    /// <summary>IANA timezone id, used to turn UTC timestamps into service days.</summary>
    public string Timezone { get; private set; } = DefaultTimezone;

    public bool IsActive { get; private set; }

    public RestaurantSettings Settings { get; private set; } = null!;

    public static Restaurant Create(
        Guid tenantId,
        string name,
        string slug,
        string? currency = null,
        string? timezone = null)
    {
        Guard.NotEmpty(tenantId);
        Guard.NotNullOrWhiteSpace(name);
        Guard.MaxLength(name, 200);

        return new Restaurant(
            Guid.CreateVersion7(),
            tenantId,
            name.Trim(),
            Slug.Of(slug),
            string.IsNullOrWhiteSpace(currency) ? DefaultCurrency : currency.ToUpperInvariant(),
            string.IsNullOrWhiteSpace(timezone) ? DefaultTimezone : timezone);
    }

    public void UpdateProfile(
        string name,
        string? address,
        string? phone,
        string? email,
        string? logoUrl)
    {
        Guard.NotNullOrWhiteSpace(name);
        Name = Guard.MaxLength(name.Trim(), 200);
        Address = address?.Trim();
        Phone = phone?.Trim();
        Email = email?.Trim().ToLowerInvariant();
        LogoUrl = logoUrl?.Trim();
    }

    public void Activate() => IsActive = true;

    public void Deactivate() => IsActive = false;
}
