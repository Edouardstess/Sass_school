namespace STED.RestaurantOS.Infrastructure.Idempotency;

/// <summary>
/// Remembers that a given client request was already carried out, and what it
/// answered.
/// <para>
/// This row is written inside the SAME transaction as the order or payment it
/// protects. Writing it afterwards would leave a window where a crash produces
/// a real order with no idempotency trace — and the client's retry would then
/// create a second one. That is precisely the bug this table exists to prevent.
/// </para>
/// <para>
/// Infrastructure, not domain: idempotency is a property of the HTTP transport,
/// not of restaurant service.
/// </para>
/// </summary>
public sealed class IdempotencyRecord
{
    private IdempotencyRecord()
    {
    }

    private IdempotencyRecord(
        Guid id,
        Guid restaurantId,
        string key,
        string endpoint,
        byte[] requestHash,
        int responseStatusCode,
        string? responseBody,
        DateTimeOffset createdAt,
        DateTimeOffset expiresAt)
    {
        Id = id;
        RestaurantId = restaurantId;
        Key = key;
        Endpoint = endpoint;
        RequestHash = requestHash;
        ResponseStatusCode = responseStatusCode;
        ResponseBody = responseBody;
        CreatedAt = createdAt;
        ExpiresAt = expiresAt;
    }

    public Guid Id { get; private set; }

    public Guid RestaurantId { get; private set; }

    /// <summary>The client-generated key, stable across network retries.</summary>
    public string Key { get; private set; } = null!;

    public string Endpoint { get; private set; } = null!;

    /// <summary>
    /// Hash of the request body. Same key with a different body is a client bug
    /// or an attack, not a retry, and is rejected rather than replayed.
    /// </summary>
    public byte[] RequestHash { get; private set; } = [];

    public int ResponseStatusCode { get; private set; }

    public string? ResponseBody { get; private set; }

    public DateTimeOffset CreatedAt { get; private set; }

    public DateTimeOffset ExpiresAt { get; private set; }

    public static IdempotencyRecord Create(
        Guid restaurantId,
        string key,
        string endpoint,
        byte[] requestHash,
        int responseStatusCode,
        string? responseBody,
        DateTimeOffset createdAt,
        TimeSpan retention)
        => new(
            Guid.CreateVersion7(),
            restaurantId,
            key,
            endpoint,
            requestHash,
            responseStatusCode,
            responseBody,
            createdAt,
            createdAt.Add(retention));

    public bool Matches(byte[] candidateHash) => RequestHash.AsSpan().SequenceEqual(candidateHash);
}
