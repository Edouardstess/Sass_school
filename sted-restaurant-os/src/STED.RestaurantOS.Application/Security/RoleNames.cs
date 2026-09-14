using STED.RestaurantOS.Domain.Staff;

namespace STED.RestaurantOS.Application.Security;

public static class RoleNames
{
    public const string SuperAdmin = "SUPER_ADMIN";
    public const string RestaurantAdmin = "RESTAURANT_ADMIN";
    public const string Manager = "MANAGER";
    public const string Cashier = "CASHIER";
    public const string Waiter = "WAITER";
    public const string Kitchen = "KITCHEN";
    public const string Bar = "BAR";
    public const string Staff = "STAFF";

    public static IReadOnlyList<string> All { get; } =
    [
        SuperAdmin, RestaurantAdmin, Manager, Cashier, Waiter, Kitchen, Bar, Staff,
    ];

    public static string For(StaffRole role) => role switch
    {
        StaffRole.SuperAdmin => SuperAdmin,
        StaffRole.RestaurantAdmin => RestaurantAdmin,
        StaffRole.Manager => Manager,
        StaffRole.Cashier => Cashier,
        StaffRole.Waiter => Waiter,
        StaffRole.Kitchen => Kitchen,
        StaffRole.Bar => Bar,
        _ => Staff,
    };
}

/// <summary>
/// The default permission set per role — the matrix from the architecture
/// document, as data.
/// <para>
/// Read it as "what this role may attempt". Several of these are further
/// narrowed at resource level: a waiter has <c>Orders.Cancel</c>, but only for
/// orders on a table they are serving.
/// </para>
/// </summary>
public static class RolePermissionDefaults
{
    private static readonly string[] KitchenSet =
    [
        Permissions.OrdersView, Permissions.MenuView,
        Permissions.KitchenView, Permissions.KitchenManage,
    ];

    private static readonly string[] BarSet =
    [
        Permissions.OrdersView, Permissions.MenuView,
        Permissions.BarView, Permissions.BarManage,
    ];

    private static readonly string[] WaiterSet =
    [
        Permissions.OrdersView, Permissions.OrdersCreate, Permissions.OrdersUpdate,
        Permissions.OrdersCancel, Permissions.OrdersServe,
        Permissions.TablesView, Permissions.TablesAssign, Permissions.TablesTransfer,
        Permissions.MenuView, Permissions.KitchenView, Permissions.BarView,
        Permissions.PaymentsView,
    ];

    private static readonly string[] CashierSet =
    [
        Permissions.OrdersView, Permissions.OrdersCreate, Permissions.OrdersUpdate,
        Permissions.TablesView, Permissions.MenuView,
        Permissions.PaymentsView, Permissions.PaymentsCreate,
    ];

    private static readonly string[] ManagerSet =
    [
        .. Permissions.All.Where(p => p is not (Permissions.RestaurantManage or Permissions.AuditView)),
    ];

    public static IReadOnlyDictionary<string, IReadOnlyList<string>> Map { get; } =
        new Dictionary<string, IReadOnlyList<string>>(StringComparer.Ordinal)
        {
            [RoleNames.SuperAdmin] = Permissions.All,
            [RoleNames.RestaurantAdmin] = Permissions.All,
            [RoleNames.Manager] = ManagerSet,
            [RoleNames.Cashier] = CashierSet,
            [RoleNames.Waiter] = WaiterSet,
            [RoleNames.Kitchen] = KitchenSet,
            [RoleNames.Bar] = BarSet,
            [RoleNames.Staff] = [Permissions.TablesView, Permissions.MenuView],
        };

    public static IReadOnlyList<string> For(string roleName)
        => Map.TryGetValue(roleName, out var permissions) ? permissions : [];
}
