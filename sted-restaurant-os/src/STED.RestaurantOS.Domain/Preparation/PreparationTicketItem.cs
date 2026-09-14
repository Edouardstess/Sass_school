using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.Domain.Preparation;

/// <summary>
/// One line on a station ticket. Carries only what a cook needs to read at two
/// metres: what, how many, and any special instruction — no prices.
/// </summary>
public sealed class PreparationTicketItem : Entity
{
    private PreparationTicketItem()
    {
    }

    private PreparationTicketItem(
        Guid id,
        Guid preparationTicketId,
        Guid orderItemId,
        string productNameSnapshot,
        int quantity,
        string? notes,
        string? modifiersSummary)
        : base(id)
    {
        PreparationTicketId = preparationTicketId;
        OrderItemId = orderItemId;
        ProductNameSnapshot = productNameSnapshot;
        Quantity = quantity;
        Notes = notes;
        ModifiersSummary = modifiersSummary;
        Status = PreparationTicketItemStatus.Pending;
    }

    public Guid PreparationTicketId { get; private set; }

    public Guid OrderItemId { get; private set; }

    public string ProductNameSnapshot { get; private set; } = null!;

    public int Quantity { get; private set; }

    public string? Notes { get; private set; }

    /// <summary>Flattened modifier text, e.g. "no onion, extra cheese".</summary>
    public string? ModifiersSummary { get; private set; }

    public PreparationTicketItemStatus Status { get; private set; }

    internal static PreparationTicketItem Create(
        Guid preparationTicketId,
        Guid orderItemId,
        string productName,
        int quantity,
        string? notes,
        string? modifiersSummary)
        => new(Guid.CreateVersion7(), preparationTicketId, orderItemId, productName, quantity, notes, modifiersSummary);

    internal void MarkInPreparation() => Status = PreparationTicketItemStatus.InPreparation;

    internal void MarkReady() => Status = PreparationTicketItemStatus.Ready;

    internal void MarkCancelled() => Status = PreparationTicketItemStatus.Cancelled;
}
