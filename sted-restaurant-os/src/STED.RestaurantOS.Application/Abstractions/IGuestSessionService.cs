using STED.RestaurantOS.Application.Authentication.Dtos;
using STED.RestaurantOS.Application.Common;

namespace STED.RestaurantOS.Application.Abstractions;

public interface IGuestSessionService
{
    /// <summary>
    /// Turns a scanned QR token into a guest session.
    /// <para>
    /// Validates the token against its stored hash, joins the table's open
    /// session or opens one, and issues a token scoped to that session alone.
    /// An unknown, expired or retired token answers 404 without revealing
    /// whether the table exists.
    /// </para>
    /// </summary>
    Task<Result<GuestSession>> ResolveAsync(string clearToken, CancellationToken ct);

    /// <summary>Calls the waiter. Nothing more — it must not be usable as a broadcast channel.</summary>
    Task<Result> CallWaiterAsync(Guid tableSessionId, CancellationToken ct);

    Task<Result> RequestBillAsync(Guid tableSessionId, CancellationToken ct);
}
