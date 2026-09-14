namespace STED.RestaurantOS.Domain.Staff;

/// <summary>
/// The staff member's primary function. Authorisation is driven by granular
/// permissions, not by this value; it exists to drive default permission sets,
/// the landing screen after login, and the staff directory.
/// </summary>
public enum StaffRole
{
    SuperAdmin = 0,
    RestaurantAdmin = 1,
    Manager = 2,
    Cashier = 3,
    Waiter = 4,
    Kitchen = 5,
    Bar = 6,
    Staff = 7,
}
