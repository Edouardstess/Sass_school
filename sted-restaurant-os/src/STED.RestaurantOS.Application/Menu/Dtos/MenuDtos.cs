namespace STED.RestaurantOS.Application.Menu.Dtos;

public sealed record StationDto(Guid Id, string Code, string Name, int DisplayOrder, bool IsActive);

public sealed record MenuCategoryDto(
    Guid Id,
    string Name,
    string? Description,
    string? ImageUrl,
    int DisplayOrder,
    bool IsActive,
    int ProductCount);

public sealed record ModifierOptionDto(
    Guid Id,
    string Name,
    decimal PriceDelta,
    bool IsDefault,
    bool IsAvailable,
    int DisplayOrder);

public sealed record ProductModifierDto(
    Guid Id,
    string Name,
    bool IsRequired,
    int MinSelections,
    int MaxSelections,
    int DisplayOrder,
    IReadOnlyList<ModifierOptionDto> Options);

public sealed record ProductDto
{
    public required Guid Id { get; init; }

    public required string Name { get; init; }

    public string? Description { get; init; }

    public string? ImageUrl { get; init; }

    public required decimal Price { get; init; }

    public required string Currency { get; init; }

    public required decimal TaxRate { get; init; }

    public required Guid CategoryId { get; init; }

    public string? CategoryName { get; init; }

    public required Guid StationId { get; init; }

    public required string StationCode { get; init; }

    public required int PreparationMinutes { get; init; }

    public required bool IsAvailable { get; init; }

    public required bool IsActive { get; init; }

    public required int DisplayOrder { get; init; }

    public IReadOnlyList<ProductModifierDto> Modifiers { get; init; } = [];
}

/// <summary>The whole menu in one response: a phone on a weak connection should fetch it once.</summary>
public sealed record MenuDto(
    Guid RestaurantId,
    string RestaurantName,
    string Currency,
    IReadOnlyList<MenuCategoryDto> Categories,
    IReadOnlyList<ProductDto> Products);

public sealed record CreateProductRequest
{
    public required string Name { get; init; }

    public string? Description { get; init; }

    public string? ImageUrl { get; init; }

    public required decimal Price { get; init; }

    public decimal? TaxRate { get; init; }

    public required Guid CategoryId { get; init; }

    public required Guid StationId { get; init; }

    public int PreparationMinutes { get; init; } = 10;

    public int DisplayOrder { get; init; }
}

public sealed record UpdateAvailabilityRequest
{
    public required bool IsAvailable { get; init; }
}
