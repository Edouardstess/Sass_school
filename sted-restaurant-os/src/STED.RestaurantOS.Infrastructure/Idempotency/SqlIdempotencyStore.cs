using System.Security.Cryptography;
using System.Text;
using Microsoft.EntityFrameworkCore;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Infrastructure.Persistence;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Infrastructure.Idempotency;

public sealed class SqlIdempotencyStore : IIdempotencyStore
{
    private static readonly TimeSpan Retention = TimeSpan.FromHours(24);

    private readonly AppDbContext _db;
    private readonly IDateTimeProvider _clock;

    public SqlIdempotencyStore(AppDbContext db, IDateTimeProvider clock)
    {
        _db = db;
        _clock = clock;
    }

    public async Task<IdempotentReplay?> FindAsync(
        Guid restaurantId,
        string key,
        string endpoint,
        byte[] requestHash,
        CancellationToken cancellationToken)
    {
        var existing = await _db.IdempotencyRecords
            .FirstOrDefaultAsync(
                r => r.RestaurantId == restaurantId && r.Key == key && r.Endpoint == endpoint,
                cancellationToken);

        if (existing is null)
        {
            return null;
        }

        if (!existing.Matches(requestHash))
        {
            // Same key, different body. That is not a retry — it is a client bug
            // or someone probing, and replaying the old answer would hide it.
            throw new BusinessRuleViolationException(
                ErrorCodes.IdempotencyKeyReused,
                "This idempotency key was already used for a different request.");
        }

        return new IdempotentReplay(existing.ResponseStatusCode, existing.ResponseBody);
    }

    public Task RecordAsync(
        Guid restaurantId,
        string key,
        string endpoint,
        byte[] requestHash,
        int statusCode,
        string? responseBody,
        CancellationToken cancellationToken)
    {
        _ = cancellationToken;

        // Added to the same change tracker as the order or payment it protects,
        // so it commits with them or not at all.
        _db.IdempotencyRecords.Add(IdempotencyRecord.Create(
            restaurantId,
            key,
            endpoint,
            requestHash,
            statusCode,
            responseBody,
            _clock.UtcNow,
            Retention));

        return Task.CompletedTask;
    }

    public byte[] HashRequest(string body) => SHA256.HashData(Encoding.UTF8.GetBytes(body ?? string.Empty));
}
