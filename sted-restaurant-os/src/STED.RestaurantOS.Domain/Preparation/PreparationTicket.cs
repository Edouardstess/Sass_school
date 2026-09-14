using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;
using STED.RestaurantOS.Domain.Preparation.Events;
using STED.RestaurantOS.Shared;
using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Domain.Preparation;

/// <summary>
/// One order, one station, one screen. A guest ordering a pizza and a mojito
/// produces two independent tickets that advance at their own pace.
/// <para>
/// Deliberately a separate aggregate from <see cref="Ordering.Order"/>: a cook
/// tapping "accept" must not take a lock on the whole order and collide with a
/// guest adding a line. The two are reconciled by domain events, and the order's
/// status is derived from its tickets — eventual consistency measured in
/// milliseconds, in exchange for no contention at the busiest moment of service.
/// </para>
/// </summary>
public sealed class PreparationTicket : AuditableAggregateRoot, ITenantEntity, IHasRowVersion
{
    private readonly List<PreparationTicketItem> _items = [];

    private PreparationTicket()
    {
    }

    private PreparationTicket(
        Guid id,
        Guid restaurantId,
        Guid orderId,
        Guid stationId,
        string ticketNumber,
        DateTimeOffset createdAt)
        : base(id)
    {
        RestaurantId = restaurantId;
        OrderId = orderId;
        StationId = stationId;
        TicketNumber = ticketNumber;
        Status = PreparationTicketStatus.New;
        CreatedAtUtc = createdAt;
    }

    public Guid RestaurantId { get; private set; }

    public Guid OrderId { get; private set; }

    public Guid StationId { get; private set; }

    public string TicketNumber { get; private set; } = null!;

    public PreparationTicketStatus Status { get; private set; }

    /// <summary>
    /// When the ticket hit the station. Distinct from the audit CreatedAt so the
    /// elapsed-time colour coding never depends on persistence plumbing.
    /// </summary>
    public DateTimeOffset CreatedAtUtc { get; private set; }

    public DateTimeOffset? AcceptedAt { get; private set; }

    public DateTimeOffset? StartedAt { get; private set; }

    public DateTimeOffset? ReadyAt { get; private set; }

    public DateTimeOffset? PickedUpAt { get; private set; }

    public Guid? AcceptedBy { get; private set; }

    public Guid? CompletedBy { get; private set; }

    public byte[]? RowVersion { get; private set; }

    public IReadOnlyCollection<PreparationTicketItem> Items => _items.AsReadOnly();

    public bool IsOpen => Status is PreparationTicketStatus.New
        or PreparationTicketStatus.Accepted
        or PreparationTicketStatus.InPreparation;

    public TimeSpan ElapsedAt(DateTimeOffset now) => (ReadyAt ?? now) - CreatedAtUtc;

    /// <summary>Late when it has been open longer than the venue's threshold.</summary>
    public bool IsLateAt(DateTimeOffset now, int lateThresholdMinutes)
        => IsOpen && ElapsedAt(now) > TimeSpan.FromMinutes(lateThresholdMinutes);

    public static PreparationTicket Create(
        Guid restaurantId,
        Guid orderId,
        Guid stationId,
        string ticketNumber,
        DateTimeOffset createdAt)
    {
        Guard.NotEmpty(restaurantId);
        Guard.NotEmpty(orderId);
        Guard.NotEmpty(stationId);
        Guard.NotNullOrWhiteSpace(ticketNumber);

        return new PreparationTicket(
            Guid.CreateVersion7(), restaurantId, orderId, stationId,
            Guard.MaxLength(ticketNumber, 24), createdAt);
    }

    public PreparationTicketItem AddItem(
        Guid orderItemId,
        string productName,
        int quantity,
        string? notes = null,
        string? modifiersSummary = null)
    {
        var item = PreparationTicketItem.Create(Id, orderItemId, productName, quantity, notes, modifiersSummary);
        _items.Add(item);
        return item;
    }

    /// <summary>Raised once the ticket is fully composed, so handlers see a complete ticket.</summary>
    public void PublishCreated(DateTimeOffset at)
        => Raise(new PreparationTicketCreatedDomainEvent(
            RestaurantId, Id, OrderId, StationId, TicketNumber, _items.Count, at));

    public void Accept(Guid acceptedBy, DateTimeOffset at)
    {
        Transition(PreparationTicketStatus.Accepted, acceptedBy, at);
        AcceptedAt = at;
        AcceptedBy = acceptedBy;
    }

    public void Start(Guid startedBy, DateTimeOffset at)
    {
        Transition(PreparationTicketStatus.InPreparation, startedBy, at);
        StartedAt = at;
        AcceptedAt ??= at;
        AcceptedBy ??= startedBy;

        foreach (var item in _items.Where(i => i.Status == PreparationTicketItemStatus.Pending))
        {
            item.MarkInPreparation();
        }
    }

    public void MarkReady(Guid completedBy, DateTimeOffset at)
    {
        Transition(PreparationTicketStatus.Ready, completedBy, at);
        ReadyAt = at;
        CompletedBy = completedBy;
        StartedAt ??= at;

        foreach (var item in _items.Where(i => i.Status != PreparationTicketItemStatus.Cancelled))
        {
            item.MarkReady();
        }

        Raise(new PreparationTicketReadyDomainEvent(RestaurantId, Id, OrderId, StationId, at));
    }

    public void MarkPickedUp(Guid pickedUpBy, DateTimeOffset at)
    {
        Transition(PreparationTicketStatus.PickedUp, pickedUpBy, at);
        PickedUpAt = at;
    }

    public void Cancel(Guid cancelledBy, DateTimeOffset at)
    {
        Transition(PreparationTicketStatus.Cancelled, cancelledBy, at);

        foreach (var item in _items)
        {
            item.MarkCancelled();
        }
    }

    private void Transition(PreparationTicketStatus next, Guid? changedBy, DateTimeOffset at)
    {
        if (Status == next)
        {
            return;
        }

        if (Status is PreparationTicketStatus.PickedUp or PreparationTicketStatus.Cancelled)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.InvalidOrderStatusTransition,
                $"A {Status} ticket cannot change status.");
        }

        // Forward-only, except cancellation which may happen from any open state.
        if (next != PreparationTicketStatus.Cancelled && next < Status)
        {
            throw new BusinessRuleViolationException(
                ErrorCodes.InvalidOrderStatusTransition,
                $"A ticket cannot go back from {Status} to {next}.");
        }

        var previous = Status;
        Status = next;
        Raise(new PreparationTicketStatusChangedDomainEvent(
            RestaurantId, Id, OrderId, StationId, previous, next, changedBy, at));
    }
}
