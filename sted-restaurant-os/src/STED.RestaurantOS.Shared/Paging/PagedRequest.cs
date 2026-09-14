namespace STED.RestaurantOS.Shared.Paging;

public enum SortDirection
{
    Ascending = 0,
    Descending = 1,
}

/// <summary>
/// Query-string contract shared by every list endpoint.
/// Page size is clamped server-side: a client asking for 100 000 rows gets 100.
/// </summary>
public record PagedRequest
{
    public const int MaxPageSize = 100;
    public const int DefaultPageSize = 20;

    private readonly int _page = 1;
    private readonly int _pageSize = DefaultPageSize;

    public int Page
    {
        get => _page;
        init => _page = value < 1 ? 1 : value;
    }

    public int PageSize
    {
        get => _pageSize;
        init => _pageSize = value switch
        {
            < 1 => DefaultPageSize,
            > MaxPageSize => MaxPageSize,
            _ => value,
        };
    }

    public string? SortBy { get; init; }

    public SortDirection SortDirection { get; init; } = SortDirection.Descending;

    public string? Search { get; init; }

    public int Skip => (Page - 1) * PageSize;
}
