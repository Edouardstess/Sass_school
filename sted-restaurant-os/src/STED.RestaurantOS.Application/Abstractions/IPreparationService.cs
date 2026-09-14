using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Preparation.Dtos;

namespace STED.RestaurantOS.Application.Abstractions;

public interface IPreparationService
{
    /// <summary>
    /// The complete state of one station's screen. Called on every reconnect and
    /// on a timer, so a station is never left looking at stale tickets.
    /// </summary>
    Task<Result<StationSnapshotDto>> GetSnapshotAsync(Guid stationId, CancellationToken ct);

    Task<Result<StationSnapshotDto>> GetSnapshotByCodeAsync(string stationCode, CancellationToken ct);

    Task<Result<PreparationTicketDto>> AcceptAsync(Guid ticketId, CancellationToken ct);

    Task<Result<PreparationTicketDto>> StartAsync(Guid ticketId, CancellationToken ct);

    /// <summary>
    /// Marks a station's part done. When every station on the order is done the
    /// order becomes READY and the waiter is notified; until then it is
    /// PARTIALLY_READY, so drinks can go out while a main course cooks.
    /// </summary>
    Task<Result<PreparationTicketDto>> MarkReadyAsync(Guid ticketId, CancellationToken ct);

    Task<Result<PreparationTicketDto>> MarkPickedUpAsync(Guid ticketId, CancellationToken ct);
}
