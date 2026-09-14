namespace STED.RestaurantOS.Application.Abstractions;

public sealed record IdempotentReplay(int StatusCode, string? Body);

/// <summary>
/// Makes a repeated request harmless.
/// <para>
/// The record is written inside the same transaction as the operation it
/// protects. Writing it afterwards leaves a window in which a crash produces a
/// real order with no trace of it — and the client's retry then creates a
/// second one. That is the exact bug this exists to prevent.
/// </para>
/// </summary>
public interface IIdempotencyStore
{
    /// <summary>
    /// Returns the stored response when this key has already been handled with
    /// the same body; throws when the same key arrives with a different body,
    /// which is a client bug or an attack rather than a retry.
    /// </summary>
    Task<IdempotentReplay?> FindAsync(
        Guid restaurantId,
        string key,
        string endpoint,
        byte[] requestHash,
        CancellationToken cancellationToken);

    Task RecordAsync(
        Guid restaurantId,
        string key,
        string endpoint,
        byte[] requestHash,
        int statusCode,
        string? responseBody,
        CancellationToken cancellationToken);

    byte[] HashRequest(string body);
}
