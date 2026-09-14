using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Menu.Dtos;

namespace STED.RestaurantOS.Application.Abstractions;

public interface IMenuService
{
    /// <summary>
    /// The full menu for the current restaurant.
    /// <para>
    /// <paramref name="availableOnly"/> is what a guest sees: a product the
    /// kitchen has run out of should not be on their screen at all, rather than
    /// greyed out and disappointing.
    /// </para>
    /// </summary>
    Task<MenuDto> GetMenuAsync(bool availableOnly, CancellationToken ct);

    Task<IReadOnlyList<MenuCategoryDto>> GetCategoriesAsync(CancellationToken ct);

    Task<IReadOnlyList<ProductDto>> GetProductsAsync(Guid? categoryId, CancellationToken ct);

    Task<Result<ProductDto>> GetProductAsync(Guid productId, CancellationToken ct);

    Task<Result<ProductDto>> CreateProductAsync(CreateProductRequest request, CancellationToken ct);

    /// <summary>The one-tap "we're out of that" the kitchen uses mid-service.</summary>
    Task<Result<ProductDto>> SetAvailabilityAsync(Guid productId, bool isAvailable, CancellationToken ct);

    Task<IReadOnlyList<StationDto>> GetStationsAsync(CancellationToken ct);
}
