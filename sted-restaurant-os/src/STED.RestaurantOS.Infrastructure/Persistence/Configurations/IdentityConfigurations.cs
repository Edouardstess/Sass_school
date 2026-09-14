using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using STED.RestaurantOS.Infrastructure.Identity;

namespace STED.RestaurantOS.Infrastructure.Persistence.Configurations;

internal sealed class ApplicationUserConfiguration : IEntityTypeConfiguration<ApplicationUser>
{
    public void Configure(EntityTypeBuilder<ApplicationUser> builder)
    {
        builder.Property(u => u.PinHash).HasMaxLength(256);

        builder.HasIndex(u => u.RestaurantId).HasDatabaseName("IX_AspNetUsers_RestaurantId");

        // Employee-code sign-in resolves through StaffProfiles, so no unique
        // constraint here: the same person may exist in two venues one day.
    }
}

internal sealed class ApplicationRoleConfiguration : IEntityTypeConfiguration<ApplicationRole>
{
    public void Configure(EntityTypeBuilder<ApplicationRole> builder)
    {
        builder.Property(r => r.Description).HasMaxLength(200);
        builder.HasIndex(r => r.RestaurantId).HasDatabaseName("IX_AspNetRoles_RestaurantId");
    }
}

internal sealed class RefreshTokenConfiguration : IEntityTypeConfiguration<RefreshToken>
{
    public void Configure(EntityTypeBuilder<RefreshToken> builder)
    {
        builder.ToTable("RefreshTokens");
        builder.HasKey(t => t.Id);

        // The token itself is never stored, only its SHA-256 hash: a database
        // dump yields nothing a thief can present.
        builder.Property(t => t.TokenHash).HasColumnType("varbinary(32)").IsRequired();
        builder.Property(t => t.CreatedByIp).HasMaxLength(45).IsUnicode(false);
        builder.Property(t => t.RevokedReason).HasMaxLength(120);

        builder.HasIndex(t => t.TokenHash).IsUnique().HasDatabaseName("UX_RefreshTokens_TokenHash");
        builder.HasIndex(t => t.UserId).HasDatabaseName("IX_RefreshTokens_UserId");
        builder.HasIndex(t => t.ExpiresAt).HasDatabaseName("IX_RefreshTokens_ExpiresAt");

        builder.HasOne<ApplicationUser>()
            .WithMany()
            .HasForeignKey(t => t.UserId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}

internal sealed class PermissionConfiguration : IEntityTypeConfiguration<Permission>
{
    public void Configure(EntityTypeBuilder<Permission> builder)
    {
        builder.ToTable("Permissions");
        builder.HasKey(p => p.Id);

        builder.Property(p => p.Code).HasMaxLength(60).IsUnicode(false).IsRequired();
        builder.Property(p => p.Module).HasMaxLength(40).IsUnicode(false).IsRequired();
        builder.Property(p => p.Description).HasMaxLength(200);

        builder.HasIndex(p => p.Code).IsUnique().HasDatabaseName("UX_Permissions_Code");
    }
}

internal sealed class RolePermissionConfiguration : IEntityTypeConfiguration<RolePermission>
{
    public void Configure(EntityTypeBuilder<RolePermission> builder)
    {
        builder.ToTable("RolePermissions");
        builder.HasKey(rp => new { rp.RoleId, rp.PermissionId });

        builder.HasOne<ApplicationRole>().WithMany().HasForeignKey(rp => rp.RoleId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.HasOne<Permission>().WithMany().HasForeignKey(rp => rp.PermissionId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}

internal sealed class UserPermissionOverrideConfiguration : IEntityTypeConfiguration<UserPermissionOverride>
{
    public void Configure(EntityTypeBuilder<UserPermissionOverride> builder)
    {
        builder.ToTable("UserPermissionOverrides");
        builder.HasKey(o => new { o.UserId, o.PermissionId });

        builder.Property(o => o.Reason).HasMaxLength(200);

        builder.HasOne<ApplicationUser>().WithMany().HasForeignKey(o => o.UserId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.HasOne<Permission>().WithMany().HasForeignKey(o => o.PermissionId)
            .OnDelete(DeleteBehavior.Cascade);
    }
}
