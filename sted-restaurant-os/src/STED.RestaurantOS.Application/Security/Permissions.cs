namespace STED.RestaurantOS.Application.Security;

/// <summary>
/// The full permission catalogue. Authorisation is granular: roles are only a
/// convenient way to hand out sets of these, never the thing that is checked.
/// <para>
/// A permission answers "may this person do this kind of thing at all". Whether
/// they may do it to <em>this particular table or order</em> is a separate,
/// resource-based check — <c>Tables.Transfer</c> lets you transfer a table, it
/// does not say which one.
/// </para>
/// </summary>
public static class Permissions
{
    public const string OrdersView = "Orders.View";
    public const string OrdersCreate = "Orders.Create";
    public const string OrdersUpdate = "Orders.Update";
    public const string OrdersCancel = "Orders.Cancel";
    public const string OrdersServe = "Orders.Serve";

    public const string TablesView = "Tables.View";
    public const string TablesAssign = "Tables.Assign";
    public const string TablesTransfer = "Tables.Transfer";

    public const string MenuView = "Menu.View";
    public const string MenuManage = "Menu.Manage";

    public const string KitchenView = "Kitchen.View";
    public const string KitchenManage = "Kitchen.Manage";

    public const string BarView = "Bar.View";
    public const string BarManage = "Bar.Manage";

    public const string PaymentsView = "Payments.View";
    public const string PaymentsCreate = "Payments.Create";
    public const string PaymentsDiscount = "Payments.Discount";

    public const string ReportsView = "Reports.View";
    public const string StaffManage = "Staff.Manage";
    public const string RestaurantManage = "Restaurant.Manage";
    public const string AuditView = "Audit.View";

    public static IReadOnlyList<string> All { get; } =
    [
        OrdersView, OrdersCreate, OrdersUpdate, OrdersCancel, OrdersServe,
        TablesView, TablesAssign, TablesTransfer,
        MenuView, MenuManage,
        KitchenView, KitchenManage,
        BarView, BarManage,
        PaymentsView, PaymentsCreate, PaymentsDiscount,
        ReportsView, StaffManage, RestaurantManage, AuditView,
    ];

    public static string ModuleOf(string permission)
    {
        var separator = permission.IndexOf('.', StringComparison.Ordinal);
        return separator < 0 ? permission : permission[..separator];
    }
}
