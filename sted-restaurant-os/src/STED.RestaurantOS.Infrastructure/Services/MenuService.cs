using Microsoft.EntityFrameworkCore;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Menu.Dtos;
using STED.RestaurantOS.Domain.Catalog;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.ValueObjects;
using STED.RestaurantOS.Infrastructure.Persistence;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Infrastructure.Services;

public sealed class MenuService : IMenuService
{
    private readonly AppDbContext _db;
    private readonly IUnitOfWork _uow;
    private readonly ITenantContext _tenant;

    public MenuService(AppDbContext db, IUnitOfWork uow, ITenantContext tenant)
    {
        _db = db;
        _uow = uow;
        _tenant = tenant;
    }

    /// <inheritdoc />
    public async Task<MenuDto> GetMenuAsync(bool availableOnly, CancellationToken ct)
    {
        var restaurant = await _db.Restaurants
            .AsNoTracking()
            .Select(r => new { r.Id, r.Name, r.Currency })
            .FirstOrDefaultAsync(ct);

        if (restaurant is null)
        {
            return new MenuDto(Guid.Empty, string.Empty, "HTG", [], []);
        }

        var categories = await GetCategoriesAsync(ct);
        var products = await LoadProductsAsync(null, availableOnly, restaurant.Currency, ct);

        // A guest never sees a sold-out product at all: greying it out only
        // invites the question "why not?" at the worst moment.
        var visibleCategoryIds = availableOnly
            ? products.Select(p => p.CategoryId).ToHashSet()
            : categories.Select(c => c.Id).ToHashSet();

        return new MenuDto(
            restaurant.Id,
            restaurant.Name,
            restaurant.Currency,
            categories.Where(c => visibleCategoryIds.Contains(c.Id)).ToList(),
            products);
    }

    public async Task<IReadOnlyList<MenuCategoryDto>> GetCategoriesAsync(CancellationToken ct)
        => await _db.MenuCategories
            .AsNoTracking()
            .Where(c => c.IsActive)
            .OrderBy(c => c.DisplayOrder)
            .ThenBy(c => c.Name)
            .Select(c => new MenuCategoryDto(
                c.Id,
                c.Name,
                c.Description,
                c.ImageUrl,
                c.DisplayOrder,
                c.IsActive,
                _db.Products.Count(p => p.CategoryId == c.Id && p.IsActive)))
            .ToListAsync(ct);

    public async Task<IReadOnlyList<ProductDto>> GetProductsAsync(Guid? categoryId, CancellationToken ct)
    {
        var currency = await CurrencyAsync(ct);
        return await LoadProductsAsync(categoryId, availableOnly: false, currency, ct);
    }

    public async Task<Result<ProductDto>> GetProductAsync(Guid productId, CancellationToken ct)
    {
        var currency = await CurrencyAsync(ct);

        var products = await LoadProductsAsync(null, false, currency, ct, productId);

        return products.Count == 0
            ? Error.NotFound("This product does not exist.")
            : products[0];
    }

    public async Task<Result<ProductDto>> CreateProductAsync(CreateProductRequest request, CancellationToken ct)
    {
        if (_tenant.RestaurantId is not { } restaurantId)
        {
            return Error.Unauthorized("No restaurant in context.");
        }

        var restaurant = await _db.Restaurants
            .Include(r => r.Settings)
            .FirstOrDefaultAsync(r => r.Id == restaurantId, ct);

        if (restaurant is null)
        {
            return Error.NotFound("Restaurant not found.");
        }

        var categoryExists = await _db.MenuCategories.AnyAsync(c => c.Id == request.CategoryId, ct);
        var stationExists = await _db.Stations.AnyAsync(s => s.Id == request.StationId, ct);

        if (!categoryExists)
        {
            return Error.Validation("Unknown category.");
        }

        // A product nobody prepares cannot be ordered, so the station is not optional.
        if (!stationExists)
        {
            return Error.Validation("Unknown station.");
        }

        var product = Product.Create(
            restaurantId,
            request.CategoryId,
            request.StationId,
            request.Name,
            Money.Of(request.Price, restaurant.Currency),
            request.TaxRate is { } rate ? TaxRate.Of(rate) : restaurant.Settings.DefaultTaxRate,
            request.PreparationMinutes,
            request.DisplayOrder);

        product.UpdateDetails(
            request.Name,
            request.Description,
            request.ImageUrl,
            request.CategoryId,
            request.StationId,
            request.PreparationMinutes,
            request.DisplayOrder);

        _db.Products.Add(product);
        await _uow.SaveChangesAsync(ct);

        return await GetProductAsync(product.Id, ct);
    }

    /// <inheritdoc />
    public async Task<Result<ProductDto>> SetAvailabilityAsync(
        Guid productId,
        bool isAvailable,
        CancellationToken ct)
    {
        var product = await _db.Products.FirstOrDefaultAsync(p => p.Id == productId, ct);

        if (product is null)
        {
            return Error.Conflict(ErrorCodes.ProductUnavailable, "This product does not exist.");
        }

        product.SetAvailability(isAvailable);
        await _uow.SaveChangesAsync(ct);

        return await GetProductAsync(productId, ct);
    }

    public async Task<IReadOnlyList<StationDto>> GetStationsAsync(CancellationToken ct)
        => await _db.Stations
            .AsNoTracking()
            .OrderBy(s => s.DisplayOrder)
            .Select(s => new StationDto(s.Id, s.Code, s.Name, s.DisplayOrder, s.IsActive))
            .ToListAsync(ct);

    private async Task<string> CurrencyAsync(CancellationToken ct)
        => await _db.Restaurants.AsNoTracking().Select(r => r.Currency).FirstOrDefaultAsync(ct) ?? "HTG";

    private async Task<IReadOnlyList<ProductDto>> LoadProductsAsync(
        Guid? categoryId,
        bool availableOnly,
        string currency,
        CancellationToken ct,
        Guid? productId = null)
    {
        var query = _db.Products
            .AsNoTracking()
            .Include(p => p.Modifiers)
            .ThenInclude(m => m.Options)
            .Where(p => p.IsActive);

        if (productId is { } id)
        {
            query = query.Where(p => p.Id == id);
        }

        if (categoryId is { } category)
        {
            query = query.Where(p => p.CategoryId == category);
        }

        if (availableOnly)
        {
            query = query.Where(p => p.IsAvailable);
        }

        var products = await query
            .OrderBy(p => p.DisplayOrder)
            .ThenBy(p => p.Name)
            .ToListAsync(ct);

        var categoryNames = await _db.MenuCategories
            .AsNoTracking()
            .ToDictionaryAsync(c => c.Id, c => c.Name, ct);

        var stationCodes = await _db.Stations
            .AsNoTracking()
            .ToDictionaryAsync(s => s.Id, s => s.Code, ct);

        return products.Select(p => new ProductDto
        {
            Id = p.Id,
            Name = p.Name,
            Description = p.Description,
            ImageUrl = p.ImageUrl,
            Price = p.Price.Amount,
            Currency = currency,
            TaxRate = p.TaxRate.Value,
            CategoryId = p.CategoryId,
            CategoryName = categoryNames.GetValueOrDefault(p.CategoryId),
            StationId = p.StationId,
            StationCode = stationCodes.GetValueOrDefault(p.StationId, StationCodes.Kitchen),
            PreparationMinutes = p.PreparationMinutes,
            IsAvailable = p.IsAvailable,
            IsActive = p.IsActive,
            DisplayOrder = p.DisplayOrder,
            Modifiers = p.Modifiers
                .OrderBy(m => m.DisplayOrder)
                .Select(m => new ProductModifierDto(
                    m.Id,
                    m.Name,
                    m.IsRequired,
                    m.MinSelections,
                    m.MaxSelections,
                    m.DisplayOrder,
                    m.Options
                        .Where(o => o.IsAvailable)
                        .OrderBy(o => o.DisplayOrder)
                        .Select(o => new ModifierOptionDto(
                            o.Id, o.Name, o.PriceDelta.Amount, o.IsDefault, o.IsAvailable, o.DisplayOrder))
                        .ToList()))
                .ToList(),
        }).ToList();
    }
}
