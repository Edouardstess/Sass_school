namespace STED.RestaurantOS.Domain.Preparation;

public enum PreparationTicketStatus
{
    /// <summary>Just landed on the station screen.</summary>
    New = 0,

    /// <summary>The station acknowledged it.</summary>
    Accepted = 1,

    InPreparation = 2,

    /// <summary>Done and waiting on the pass.</summary>
    Ready = 3,

    /// <summary>Collected by a waiter.</summary>
    PickedUp = 4,

    Cancelled = 5,
}

public enum PreparationTicketItemStatus
{
    Pending = 0,
    InPreparation = 1,
    Ready = 2,
    Cancelled = 3,
}
