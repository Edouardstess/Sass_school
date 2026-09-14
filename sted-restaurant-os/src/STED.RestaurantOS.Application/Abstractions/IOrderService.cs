using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Ordering.Dtos;
using STED.RestaurantOS.Shared.Paging;

namespace STED.RestaurantOS.Application.Abstractions;

public interface IOrderService
{
    /// <summary>Prices a cart server-side without creating anything.</summary>
    Task<Result<CartQuoteDto>> QuoteAsync(IReadOnlyList<CartLineRequest> lines, CancellationToken ct);

    /// <summary>
    /// The core transaction of the product.
    /// <para>
    /// Validates the session, re-reads every product, snapshots names and
    /// prices, recomputes the totals, resolves the responsible waiter from the
    /// session's active assignment, writes the order, its lines, its status
    /// history and one preparation ticket per station — then commits. Real-time
    /// events go out only afterwards, because a ticket on a kitchen screen for
    /// an order a rollback erased is worse than a slow one.
    /// </para>
    /// </summary>
    Task<Result<OrderDto>> PlaceOrderAsync(
        Guid tableSessionId,
        PlaceOrderRequest request,
        Domain.Ordering.OrderSource source,
        CancellationToken ct);

    Task<Result<OrderDto>> GetOrderAsync(Guid orderId, CancellationToken ct);

    Task<PagedResult<OrderDto>> SearchAsync(OrderFilter filter, PagedRequest paging, CancellationToken ct);

    Task<Result<OrderDto>> ConfirmAsync(Guid orderId, CancellationToken ct);

    Task<Result<OrderDto>> ServeAsync(Guid orderId, CancellationToken ct);

    Task<Result<OrderDto>> CancelAsync(Guid orderId, CancelOrderRequest request, CancellationToken ct);

    Task<Result<OrderDto>> ApplyDiscountAsync(Guid orderId, ApplyDiscountRequest request, CancellationToken ct);

    Task<IReadOnlyList<OrderStatusHistoryDto>> GetHistoryAsync(Guid orderId, CancellationToken ct);
}
