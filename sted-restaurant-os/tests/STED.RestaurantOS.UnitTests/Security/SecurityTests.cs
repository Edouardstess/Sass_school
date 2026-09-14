using FluentAssertions;
using STED.RestaurantOS.Application.Security;
using STED.RestaurantOS.Infrastructure.Identity;
using STED.RestaurantOS.UnitTests.Fixtures;
using Xunit;

namespace STED.RestaurantOS.UnitTests.Security;

public sealed class RolePermissionTests
{
    [Fact]
    public void Every_role_only_hands_out_permissions_that_exist()
    {
        foreach (var (role, permissions) in RolePermissionDefaults.Map)
        {
            permissions.Should().OnlyContain(
                p => Permissions.All.Contains(p),
                $"role {role} must not reference an unknown permission");
        }
    }

    [Fact]
    public void A_waiter_can_run_a_table_but_cannot_reconfigure_the_venue()
    {
        var waiter = RolePermissionDefaults.For(RoleNames.Waiter);

        waiter.Should().Contain(Permissions.TablesAssign);
        waiter.Should().Contain(Permissions.TablesTransfer);
        waiter.Should().Contain(Permissions.OrdersServe);

        waiter.Should().NotContain(Permissions.RestaurantManage);
        waiter.Should().NotContain(Permissions.StaffManage);
        waiter.Should().NotContain(Permissions.ReportsView);
    }

    /// <summary>
    /// Business test 6: reading how every waiter performed is management
    /// information, not shop-floor information.
    /// </summary>
    [Fact]
    public void Only_management_can_read_the_performance_reports()
    {
        var allowed = RolePermissionDefaults.Map
            .Where(entry => entry.Value.Contains(Permissions.ReportsView))
            .Select(entry => entry.Key)
            .ToList();

        allowed.Should().BeEquivalentTo([
            RoleNames.SuperAdmin,
            RoleNames.RestaurantAdmin,
            RoleNames.Manager,
        ]);
    }

    [Fact]
    public void A_kitchen_screen_cannot_take_money_or_place_orders()
    {
        var kitchen = RolePermissionDefaults.For(RoleNames.Kitchen);

        kitchen.Should().Contain(Permissions.KitchenManage);
        kitchen.Should().NotContain(Permissions.PaymentsCreate);
        kitchen.Should().NotContain(Permissions.OrdersCreate);
        kitchen.Should().NotContain(Permissions.BarManage);
    }

    [Fact]
    public void The_bar_cannot_touch_kitchen_tickets_and_the_kitchen_cannot_touch_the_bar()
    {
        RolePermissionDefaults.For(RoleNames.Bar).Should().NotContain(Permissions.KitchenManage);
        RolePermissionDefaults.For(RoleNames.Kitchen).Should().NotContain(Permissions.BarManage);
    }

    [Fact]
    public void Only_an_administrator_reads_the_audit_trail()
    {
        RolePermissionDefaults.For(RoleNames.Manager).Should().NotContain(Permissions.AuditView);
        RolePermissionDefaults.For(RoleNames.RestaurantAdmin).Should().Contain(Permissions.AuditView);
    }

    [Fact]
    public void A_permission_code_names_its_module()
        => Permissions.ModuleOf(Permissions.OrdersCancel).Should().Be("Orders");
}

public sealed class RefreshTokenTests
{
    private readonly TestClock _clock = TestClock.AtServiceEvening();

    [Fact]
    public void An_issued_token_is_returned_once_and_only_its_hash_is_kept()
    {
        var (clear, token) = RefreshToken.Issue(Guid.NewGuid(), _clock.UtcNow, TimeSpan.FromDays(14), "10.0.0.1");

        clear.Should().NotBeNullOrWhiteSpace();
        token.TokenHash.Should().Equal(RefreshToken.Hash(clear));

        // Nothing on the row can reproduce the token itself.
        token.TokenHash.Should().HaveCount(32);
    }

    [Fact]
    public void Two_issued_tokens_are_never_the_same()
    {
        var (first, _) = RefreshToken.Issue(Guid.NewGuid(), _clock.UtcNow, TimeSpan.FromDays(14), null);
        var (second, _) = RefreshToken.Issue(Guid.NewGuid(), _clock.UtcNow, TimeSpan.FromDays(14), null);

        first.Should().NotBe(second);
    }

    [Fact]
    public void A_token_is_active_until_it_expires()
    {
        var (_, token) = RefreshToken.Issue(Guid.NewGuid(), _clock.UtcNow, TimeSpan.FromDays(1), null);

        token.IsActiveAt(_clock.UtcNow.AddHours(23)).Should().BeTrue();
        token.IsActiveAt(_clock.UtcNow.AddHours(25)).Should().BeFalse();
    }

    [Fact]
    public void Rotation_points_the_old_token_at_its_successor()
    {
        var userId = Guid.NewGuid();
        var (_, older) = RefreshToken.Issue(userId, _clock.UtcNow, TimeSpan.FromDays(14), null);
        var (_, newer) = RefreshToken.Issue(userId, _clock.AdvanceMinutes(10), TimeSpan.FromDays(14), null);

        older.Revoke(_clock.UtcNow, "Rotated", newer.Id);

        older.IsRevoked.Should().BeTrue();
        older.ReplacedByTokenId.Should().Be(newer.Id);
        older.IsActiveAt(_clock.UtcNow).Should().BeFalse();
    }

    [Fact]
    public void Revoking_twice_keeps_the_first_reason()
    {
        var (_, token) = RefreshToken.Issue(Guid.NewGuid(), _clock.UtcNow, TimeSpan.FromDays(14), null);

        token.Revoke(_clock.UtcNow, "Signed out");
        token.Revoke(_clock.AdvanceMinutes(5), "Something else");

        token.RevokedReason.Should().Be("Signed out");
    }
}

public sealed class QrTokenFactoryTests
{
    private readonly QrTokenFactory _factory = new();

    [Fact]
    public void A_token_is_random_not_derived_from_the_table()
    {
        var first = _factory.Create();
        var second = _factory.Create();

        first.ClearToken.Should().NotBe(second.ClearToken);
        first.ClearToken.Should().NotContainAny("=", "+", "/");
    }

    [Fact]
    public void Hashing_is_stable_and_the_lookup_key_is_a_prefix()
    {
        var material = _factory.Create();

        _factory.Hash(material.ClearToken).Should().Equal(material.Hash);
        material.ClearToken.Should().StartWith(material.LookupKey);
        material.LookupKey.Should().HaveLength(12);
    }

    [Fact]
    public void A_different_token_never_matches_a_stored_hash()
    {
        var stored = _factory.Create();
        var other = _factory.Create();

        QrTokenFactory.Matches(stored.Hash, _factory.Hash(other.ClearToken)).Should().BeFalse();
        QrTokenFactory.Matches(stored.Hash, _factory.Hash(stored.ClearToken)).Should().BeTrue();
    }
}
