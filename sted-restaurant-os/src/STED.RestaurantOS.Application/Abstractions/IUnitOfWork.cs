using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Application.Abstractions;

/// <summary>
/// One business operation, one transaction.
/// <para>
/// <see cref="CommitTransactionAsync"/> returns the domain events raised during
/// the unit of work rather than publishing them itself. Real-time publication is
/// the caller's job, and it happens strictly after the commit — announcing an
/// order that a rollback then erased would put a phantom ticket on a kitchen
/// screen.
/// </para>
/// </summary>
public interface IUnitOfWork
{
    Task<int> SaveChangesAsync(CancellationToken cancellationToken);

    Task BeginTransactionAsync(CancellationToken cancellationToken);

    Task<IReadOnlyList<IDomainEvent>> CommitTransactionAsync(CancellationToken cancellationToken);

    Task RollbackTransactionAsync(CancellationToken cancellationToken);

    /// <summary>
    /// Runs the operation inside a transaction, using the provider's execution
    /// strategy so a transient retry replays the whole unit of work rather than
    /// half of it.
    /// </summary>
    Task<T> ExecuteInTransactionAsync<T>(
        Func<CancellationToken, Task<T>> operation,
        CancellationToken cancellationToken);
}
