namespace STED.RestaurantOS.Shared.Paging;

/// <summary>Envelope returned by every list endpoint.</summary>
public sealed record PagedResult<T>
{
    public required IReadOnlyList<T> Items { get; init; }

    public required int Page { get; init; }

    public required int PageSize { get; init; }

    public required int TotalCount { get; init; }

    public int TotalPages => PageSize <= 0 ? 0 : (int)Math.Ceiling(TotalCount / (double)PageSize);

    public bool HasPrevious => Page > 1;

    public bool HasNext => Page < TotalPages;

    public static PagedResult<T> Empty(int page, int pageSize) => new()
    {
        Items = Array.Empty<T>(),
        Page = page,
        PageSize = pageSize,
        TotalCount = 0,
    };
}
