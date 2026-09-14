using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Floor.Dtos;

namespace STED.RestaurantOS.Application.Abstractions;

public interface IFloorService
{
    Task<IReadOnlyList<TableDto>> GetFloorPlanAsync(Guid? zoneId, CancellationToken ct);

    Task<Result<TableDto>> GetTableAsync(Guid tableId, CancellationToken ct);

    Task<Result<TableDto>> CreateTableAsync(CreateTableRequest request, CancellationToken ct);

    /// <summary>
    /// Opens a session if the table has none, then assigns the caller to it.
    /// <para>
    /// Both halves happen in one transaction, and the database's filtered unique
    /// indexes decide the winner when two waiters tap at the same instant. The
    /// loser gets a 409 naming who actually has the table, not a silent
    /// overwrite.
    /// </para>
    /// </summary>
    Task<Result<TableSessionDto>> TakeTableAsync(Guid tableId, TakeTableRequest request, CancellationToken ct);

    Task<Result<TableSessionDto>> TransferTableAsync(
        Guid tableId,
        TransferTableRequest request,
        CancellationToken ct);

    Task<Result<TableSessionDto>> ReleaseTableAsync(
        Guid tableId,
        ReleaseTableRequest request,
        CancellationToken ct);

    Task<Result<TableSessionDto>> CloseSessionAsync(Guid sessionId, CancellationToken ct);

    Task<Result<TableSessionDto>> GetSessionAsync(Guid sessionId, CancellationToken ct);

    Task<IReadOnlyList<TableDto>> GetMyTablesAsync(CancellationToken ct);

    /// <summary>Answers "who was responsible for this table at this instant?".</summary>
    Task<Result<ResponsibleWaiterDto>> GetResponsibleWaiterAsync(
        Guid tableId,
        DateTimeOffset at,
        CancellationToken ct);

    Task<IReadOnlyList<ServiceAssignmentHistoryDto>> GetAssignmentHistoryAsync(
        Guid? tableId,
        Guid? waiterId,
        DateTimeOffset? from,
        DateTimeOffset? to,
        CancellationToken ct);

    /// <summary>
    /// Issues a new QR code and retires the previous one. The clear token is
    /// returned here and never again — it only exists to be printed.
    /// </summary>
    Task<Result<QrCodeIssuedDto>> RegenerateQrCodeAsync(Guid tableId, CancellationToken ct);
}
