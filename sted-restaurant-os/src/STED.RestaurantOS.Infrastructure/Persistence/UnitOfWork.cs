using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Storage;
using STED.RestaurantOS.Application.Abstractions;
using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Infrastructure.Persistence;

/// <summary>
/// One business operation, one transaction — and domain events handed back to
/// the caller instead of being published from inside it.
/// </summary>
public sealed class UnitOfWork : IUnitOfWork
{
    private readonly AppDbContext _db;
    private IDbContextTransaction? _transaction;

    public UnitOfWork(AppDbContext db) => _db = db;

    public Task<int> SaveChangesAsync(CancellationToken cancellationToken)
        => _db.SaveChangesAsync(cancellationToken);

    public async Task BeginTransactionAsync(CancellationToken cancellationToken)
    {
        if (_transaction is not null)
        {
            return;
        }

        _transaction = await _db.Database.BeginTransactionAsync(cancellationToken);
    }

    public async Task<IReadOnlyList<IDomainEvent>> CommitTransactionAsync(CancellationToken cancellationToken)
    {
        await _db.SaveChangesAsync(cancellationToken);

        // Drained before the commit, returned after it: the caller publishes
        // only once the data is durable.
        var events = DomainEventCollector.Collect(_db);

        if (_transaction is not null)
        {
            await _transaction.CommitAsync(cancellationToken);
            await _transaction.DisposeAsync();
            _transaction = null;
        }

        return events;
    }

    public async Task RollbackTransactionAsync(CancellationToken cancellationToken)
    {
        if (_transaction is null)
        {
            return;
        }

        await _transaction.RollbackAsync(cancellationToken);
        await _transaction.DisposeAsync();
        _transaction = null;
    }

    /// <summary>
    /// Wraps the operation in the provider's execution strategy.
    /// <para>
    /// Combining <c>EnableRetryOnFailure</c> with a manual transaction without
    /// this is the classic EF Core trap: a retry replays only part of the unit
    /// of work. On this system that could mean a second order.
    /// </para>
    /// </summary>
    public async Task<T> ExecuteInTransactionAsync<T>(
        Func<CancellationToken, Task<T>> operation,
        CancellationToken cancellationToken)
    {
        var strategy = _db.Database.CreateExecutionStrategy();

        return await strategy.ExecuteAsync(async ct =>
        {
            await BeginTransactionAsync(ct);

            try
            {
                var result = await operation(ct);
                await CommitTransactionAsync(ct);
                return result;
            }
            catch
            {
                await RollbackTransactionAsync(ct);
                throw;
            }
        }, cancellationToken);
    }
}
