using Microsoft.EntityFrameworkCore;
using STED.RestaurantOS.Application.Common;
using STED.RestaurantOS.Application.Floor.Dtos;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Infrastructure.Services;

/// <summary>
/// The read side of the floor. Everything here is a projection: a floor plan
/// refreshed every fifteen seconds on a dozen devices must not materialise
/// aggregates.
/// </summary>
public sealed partial class FloorService
{
    public async Task<IReadOnlyList<TableDto>> GetFloorPlanAsync(Guid? zoneId, CancellationToken ct)
    {
        var query = _db.RestaurantTables.AsNoTracking().Where(t => t.IsActive);

        if (zoneId is { } zone)
        {
            query = query.Where(t => t.ZoneId == zone);
        }

        return await ProjectTablesAsync(query, ct);
    }

    public async Task<Result<TableDto>> GetTableAsync(Guid tableId, CancellationToken ct)
    {
        var tables = await ProjectTablesAsync(
            _db.RestaurantTables.AsNoTracking().Where(t => t.Id == tableId), ct);

        return tables.Count == 0
            ? Error.Conflict(ErrorCodes.TableNotFound, "This table does not exist.")
            : tables[0];
    }

    /// <summary>The waiter's own screen: only the tables they are responsible for.</summary>
    public async Task<IReadOnlyList<TableDto>> GetMyTablesAsync(CancellationToken ct)
    {
        if (_tenant.StaffProfileId is not { } waiterId)
        {
            return [];
        }

        var tableIds = await _db.ServiceAssignments
            .Where(a => a.WaiterId == waiterId && a.Status == ServiceAssignmentStatus.Active)
            .Select(a => a.TableId)
            .ToListAsync(ct);

        return await ProjectTablesAsync(
            _db.RestaurantTables.AsNoTracking().Where(t => tableIds.Contains(t.Id)), ct);
    }

    public async Task<Result<TableDto>> CreateTableAsync(CreateTableRequest request, CancellationToken ct)
    {
        if (_tenant.RestaurantId is not { } restaurantId)
        {
            return Error.Unauthorized("No restaurant in context.");
        }

        var duplicate = await _db.RestaurantTables
            .AnyAsync(t => t.Number == request.Number, ct);

        if (duplicate)
        {
            return Error.Conflict(ErrorCodes.ValidationError, $"Table {request.Number} already exists.");
        }

        var table = RestaurantTable.Create(
            restaurantId, request.Number, request.Capacity, request.ZoneId, request.Name);

        // A table without a QR code cannot be ordered from, which defeats the
        // point of creating it, so one is issued immediately.
        var material = _qr.Create();
        table.IssueQrCode(material.Hash, material.LookupKey, _tenant.StaffProfileId ?? Guid.Empty, _clock.UtcNow);

        _db.RestaurantTables.Add(table);
        await _uow.SaveChangesAsync(ct);

        return await GetTableAsync(table.Id, ct);
    }

    public async Task<Result<TableSessionDto>> GetSessionAsync(Guid sessionId, CancellationToken ct)
    {
        var dto = await ProjectSessionAsync(sessionId, ct);

        return dto is null
            ? Error.NotFound("This session does not exist.")
            : dto;
    }

    /// <inheritdoc />
    public async Task<Result<ResponsibleWaiterDto>> GetResponsibleWaiterAsync(
        Guid tableId,
        DateTimeOffset at,
        CancellationToken ct)
    {
        var table = await _db.RestaurantTables
            .AsNoTracking()
            .Where(t => t.Id == tableId)
            .Select(t => new { t.Id, t.Number })
            .FirstOrDefaultAsync(ct);

        if (table is null)
        {
            return Error.Conflict(ErrorCodes.TableNotFound, "This table does not exist.");
        }

        // The assignment whose window contains the instant. Backed by
        // IX_ServiceAssignments_Restaurant_Table_Window, so this is a seek even
        // across a year of service.
        var assignment = await _db.ServiceAssignments
            .Where(a => a.TableId == tableId
                        && a.AssignedAt <= at
                        && (a.UnassignedAt == null || a.UnassignedAt > at))
            .OrderByDescending(a => a.AssignedAt)
            .Select(a => new
            {
                a.WaiterId,
                a.TableSessionId,
                a.AssignedAt,
                a.UnassignedAt,
                WaiterName = _db.StaffProfiles
                    .Where(s => s.Id == a.WaiterId)
                    .Select(s => s.DisplayName)
                    .FirstOrDefault(),
            })
            .FirstOrDefaultAsync(ct);

        return new ResponsibleWaiterDto(
            table.Id,
            table.Number,
            at,
            assignment?.WaiterId,
            assignment?.WaiterName,
            assignment?.TableSessionId,
            assignment?.AssignedAt,
            assignment?.UnassignedAt);
    }

    /// <summary>The hand-over trail. Read-only: there is no API that writes here.</summary>
    public async Task<IReadOnlyList<ServiceAssignmentHistoryDto>> GetAssignmentHistoryAsync(
        Guid? tableId,
        Guid? waiterId,
        DateTimeOffset? from,
        DateTimeOffset? to,
        CancellationToken ct)
    {
        var query = _db.ServiceAssignmentHistory.AsQueryable();

        if (tableId is { } table)
        {
            query = query.Where(h => h.TableId == table);
        }

        if (waiterId is { } waiter)
        {
            query = query.Where(h => h.PreviousWaiterId == waiter || h.NewWaiterId == waiter);
        }

        if (from is { } start)
        {
            query = query.Where(h => h.ChangedAt >= start);
        }

        if (to is { } end)
        {
            query = query.Where(h => h.ChangedAt < end);
        }

        return await query
            .OrderByDescending(h => h.ChangedAt)
            .Take(500)
            .Select(h => new ServiceAssignmentHistoryDto(
                h.Id,
                h.TableId,
                _db.RestaurantTables.Where(t => t.Id == h.TableId).Select(t => t.Number).FirstOrDefault() ?? "?",
                h.TableSessionId,
                h.PreviousWaiterId,
                _db.StaffProfiles.Where(s => s.Id == h.PreviousWaiterId).Select(s => s.DisplayName).FirstOrDefault(),
                h.NewWaiterId,
                _db.StaffProfiles.Where(s => s.Id == h.NewWaiterId).Select(s => s.DisplayName).FirstOrDefault(),
                h.Action,
                h.ChangedBy,
                _db.StaffProfiles.Where(s => s.Id == h.ChangedBy).Select(s => s.DisplayName).FirstOrDefault(),
                h.ChangedAt,
                h.Reason))
            .ToListAsync(ct);
    }

    private async Task<IReadOnlyList<TableDto>> ProjectTablesAsync(
        IQueryable<RestaurantTable> query,
        CancellationToken ct)
    {
        var now = _clock.UtcNow;

        var rows = await query
            .OrderBy(t => t.Number)
            .Select(t => new
            {
                t.Id,
                t.Number,
                t.Name,
                t.Capacity,
                t.Status,
                t.ZoneId,
                ZoneName = _db.TableZones.Where(z => z.Id == t.ZoneId).Select(z => z.Name).FirstOrDefault(),
                HasQr = t.QrCodes.Any(q => q.IsActive),
                Session = _db.TableSessions
                    .Where(s => s.TableId == t.Id
                                && (s.Status == TableSessionStatus.Open || s.Status == TableSessionStatus.Active))
                    .Select(s => new
                    {
                        s.Id,
                        s.GuestCount,
                        s.StartedAt,
                        WaiterId = s.Assignments
                            .Where(a => a.Status == ServiceAssignmentStatus.Active)
                            .Select(a => (Guid?)a.WaiterId)
                            .FirstOrDefault(),
                        OpenOrders = _db.Orders.Count(o => o.TableSessionId == s.Id
                                                           && o.Status != OrderStatus.Closed
                                                           && o.Status != OrderStatus.Cancelled),
                        Total = _db.Orders
                            .Where(o => o.TableSessionId == s.Id && o.Status != OrderStatus.Cancelled)
                            .Sum(o => (decimal?)o.Total.Amount) ?? 0m,
                    })
                    .FirstOrDefault(),
            })
            .ToListAsync(ct);

        var waiterIds = rows
            .Where(r => r.Session != null && r.Session.WaiterId != null)
            .Select(r => r.Session!.WaiterId!.Value)
            .Distinct()
            .ToList();

        var waiterNames = await _db.StaffProfiles
            .Where(s => waiterIds.Contains(s.Id))
            .ToDictionaryAsync(s => s.Id, s => s.DisplayName, ct);

        return rows.Select(r => new TableDto
        {
            Id = r.Id,
            Number = r.Number,
            Name = r.Name,
            Capacity = r.Capacity,
            Status = r.Status,
            ZoneId = r.ZoneId,
            ZoneName = r.ZoneName,
            HasActiveQrCode = r.HasQr,
            CurrentSessionId = r.Session?.Id,
            CurrentWaiterId = r.Session?.WaiterId,
            CurrentWaiterName = r.Session?.WaiterId is { } id && waiterNames.TryGetValue(id, out var name)
                ? name
                : null,
            GuestCount = r.Session?.GuestCount,
            SessionStartedAt = r.Session?.StartedAt,
            OpenOrderCount = r.Session?.OpenOrders ?? 0,
            SessionTotal = r.Session?.Total ?? 0m,
            SeatedMinutes = r.Session is null ? null : (int)(now - r.Session.StartedAt).TotalMinutes,
        }).ToList();
    }

    private async Task<TableSessionDto?> ProjectSessionAsync(Guid sessionId, CancellationToken ct)
    {
        var row = await _db.TableSessions
            .AsNoTracking()
            .Where(s => s.Id == sessionId)
            .Select(s => new
            {
                s.Id,
                s.TableId,
                s.SessionNumber,
                s.Status,
                s.GuestCount,
                s.StartedAt,
                s.EndedAt,
                s.Notes,
                TableNumber = _db.RestaurantTables
                    .Where(t => t.Id == s.TableId)
                    .Select(t => t.Number)
                    .FirstOrDefault(),
                WaiterId = s.Assignments
                    .Where(a => a.Status == ServiceAssignmentStatus.Active)
                    .Select(a => (Guid?)a.WaiterId)
                    .FirstOrDefault(),
                OrderCount = _db.Orders.Count(o => o.TableSessionId == s.Id && o.Status != OrderStatus.Cancelled),
                Total = _db.Orders
                    .Where(o => o.TableSessionId == s.Id && o.Status != OrderStatus.Cancelled)
                    .Sum(o => (decimal?)o.Total.Amount) ?? 0m,
                Paid = _db.Payments
                    .Where(p => p.TableSessionId == s.Id
                                && p.Status == Domain.Billing.PaymentStatus.Completed)
                    .Sum(p => (decimal?)p.Amount.Amount) ?? 0m,
            })
            .FirstOrDefaultAsync(ct);

        if (row is null)
        {
            return null;
        }

        var waiterName = row.WaiterId is null
            ? null
            : await _db.StaffProfiles
                .Where(s => s.Id == row.WaiterId)
                .Select(s => s.DisplayName)
                .FirstOrDefaultAsync(ct);

        return new TableSessionDto
        {
            Id = row.Id,
            TableId = row.TableId,
            TableNumber = row.TableNumber ?? "?",
            SessionNumber = row.SessionNumber,
            Status = row.Status,
            GuestCount = row.GuestCount,
            StartedAt = row.StartedAt,
            EndedAt = row.EndedAt,
            CurrentWaiterId = row.WaiterId,
            CurrentWaiterName = waiterName,
            OrderCount = row.OrderCount,
            Total = row.Total,
            Paid = row.Paid,
            Notes = row.Notes,
        };
    }
}
