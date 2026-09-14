using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Domain.Restaurants;
using STED.RestaurantOS.Domain.Staff;

namespace STED.RestaurantOS.Infrastructure.Persistence.Configurations;

internal sealed class TableZoneConfiguration : IEntityTypeConfiguration<TableZone>
{
    public void Configure(EntityTypeBuilder<TableZone> builder)
    {
        builder.ToTable("TableZones");
        builder.HasKey(z => z.Id);

        builder.Property(z => z.Name).HasMaxLength(80).IsRequired();

        builder.HasIndex(z => new { z.RestaurantId, z.Name })
            .IsUnique()
            .HasDatabaseName("UX_TableZones_Restaurant_Name");

        builder.HasOne<Restaurant>().WithMany().HasForeignKey(z => z.RestaurantId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.Ignore(z => z.DomainEvents);
    }
}

internal sealed class RestaurantTableConfiguration : IEntityTypeConfiguration<RestaurantTable>
{
    public void Configure(EntityTypeBuilder<RestaurantTable> builder)
    {
        builder.ToTable("RestaurantTables", t => t.HasCheckConstraint(
            "CK_RestaurantTables_Capacity", "[Capacity] BETWEEN 1 AND 50"));
        builder.HasKey(t => t.Id);

        builder.Property(t => t.Number).HasMaxLength(20).IsRequired();
        builder.Property(t => t.Name).HasMaxLength(80);
        builder.Property(t => t.Status).HasEnumStringConversion().IsRequired();

        builder.Property(t => t.RowVersion).IsRowVersion();


        builder.HasIndex(t => new { t.RestaurantId, t.Number })
            .IsUnique()
            .HasDatabaseName("UX_RestaurantTables_Restaurant_Number");

        builder.HasIndex(t => new { t.RestaurantId, t.Status })
            .HasDatabaseName("IX_RestaurantTables_Restaurant_Status");

        builder.HasOne<Restaurant>().WithMany().HasForeignKey(t => t.RestaurantId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.HasOne<TableZone>().WithMany().HasForeignKey(t => t.ZoneId)
            .OnDelete(DeleteBehavior.SetNull);

        // QR codes belong to the table aggregate, so they are loaded and saved with it.
        builder.HasMany(t => t.QrCodes)
            .WithOne()
            .HasForeignKey(q => q.TableId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.Metadata.FindNavigation(nameof(RestaurantTable.QrCodes))!
            .SetPropertyAccessMode(PropertyAccessMode.Field);

        builder.Ignore(t => t.DomainEvents);
        builder.Ignore(t => t.ActiveQrCode);
    }
}

internal sealed class TableQrCodeConfiguration : IEntityTypeConfiguration<TableQrCode>
{
    public void Configure(EntityTypeBuilder<TableQrCode> builder)
    {
        builder.ToTable("TableQrCodes");
        builder.HasKey(q => q.Id);

        // The token itself never lands in the database: only its SHA-256 hash.
        builder.Property(q => q.SecureTokenHash)
            .HasColumnType("varbinary(32)")
            .IsRequired();

        builder.Property(q => q.TokenLookupKey)
            .HasMaxLength(TableQrCode.LookupKeyLength)
            .IsUnicode(false)
            .IsRequired();

        builder.HasIndex(q => q.SecureTokenHash)
            .IsUnique()
            .HasDatabaseName("UX_TableQrCodes_TokenHash");

        builder.HasIndex(q => q.TokenLookupKey)
            .HasDatabaseName("IX_TableQrCodes_LookupKey");

        // RG-011: at most one active QR code per table, guaranteed by the database
        // and not merely by the aggregate that tries to keep it true.
        builder.HasIndex(q => q.TableId)
            .IsUnique()
            .HasFilter("[IsActive] = 1")
            .HasDatabaseName("UX_TableQrCodes_ActiveByTable");
    }
}

internal sealed class TableSessionConfiguration : IEntityTypeConfiguration<TableSession>
{
    public void Configure(EntityTypeBuilder<TableSession> builder)
    {
        builder.ToTable("TableSessions", t => t.HasCheckConstraint(
            "CK_TableSessions_GuestCount", "[GuestCount] BETWEEN 1 AND 99"));
        builder.HasKey(s => s.Id);

        builder.Property(s => s.Status).HasEnumStringConversion().IsRequired();
        builder.Property(s => s.Notes).HasMaxLength(500);
        builder.Property(s => s.RowVersion).IsRowVersion();


        // RG-020: one open session per table. This filtered unique index is the
        // real guarantee — two concurrent requests cannot both win, whatever the
        // application code does.
        builder.HasIndex(s => s.TableId)
            .IsUnique()
            .HasFilter("[Status] IN ('Open', 'Active')")
            .HasDatabaseName("UX_TableSessions_OpenByTable");

        builder.HasIndex(s => new { s.RestaurantId, s.StartedAt })
            .HasDatabaseName("IX_TableSessions_Restaurant_StartedAt");

        builder.HasIndex(s => new { s.RestaurantId, s.Status, s.TableId })
            .HasDatabaseName("IX_TableSessions_Restaurant_Status_Table");

        builder.HasOne<Restaurant>().WithMany().HasForeignKey(s => s.RestaurantId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.HasOne<RestaurantTable>().WithMany().HasForeignKey(s => s.TableId)
            .OnDelete(DeleteBehavior.Restrict);

        // Assignments and their history are part of this aggregate: they are
        // never loaded or written on their own.
        builder.HasMany(s => s.Assignments)
            .WithOne()
            .HasForeignKey(a => a.TableSessionId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.HasMany(s => s.History)
            .WithOne()
            .HasForeignKey(h => h.TableSessionId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.Metadata.FindNavigation(nameof(TableSession.Assignments))!
            .SetPropertyAccessMode(PropertyAccessMode.Field);
        builder.Metadata.FindNavigation(nameof(TableSession.History))!
            .SetPropertyAccessMode(PropertyAccessMode.Field);

        builder.Ignore(s => s.DomainEvents);
        builder.Ignore(s => s.CurrentAssignment);
        builder.Ignore(s => s.CurrentWaiterId);
    }
}

internal sealed class ServiceAssignmentConfiguration : IEntityTypeConfiguration<ServiceAssignment>
{
    public void Configure(EntityTypeBuilder<ServiceAssignment> builder)
    {
        builder.ToTable("ServiceAssignments");
        builder.HasKey(a => a.Id);

        builder.Property(a => a.Status).HasEnumStringConversion().IsRequired();
        builder.Property(a => a.Notes).HasMaxLength(300);

        // RG-030, the single most important constraint in the schema: one active
        // waiter per session. A lost race becomes a unique-violation the API
        // turns into 409 TABLE_ALREADY_ASSIGNED, never two owners of one table.
        builder.HasIndex(a => a.TableSessionId)
            .IsUnique()
            .HasFilter("[Status] = 'Active'")
            .HasDatabaseName("UX_ServiceAssignments_ActiveBySession");

        builder.HasIndex(a => new { a.RestaurantId, a.WaiterId, a.AssignedAt })
            .HasDatabaseName("IX_ServiceAssignments_Restaurant_Waiter_AssignedAt");

        // Answers "who was responsible for table 8 at 20:15?" with an index seek.
        builder.HasIndex(a => new { a.RestaurantId, a.TableId, a.AssignedAt, a.UnassignedAt })
            .HasDatabaseName("IX_ServiceAssignments_Restaurant_Table_Window");

        builder.HasOne<StaffProfile>().WithMany().HasForeignKey(a => a.WaiterId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.HasOne<RestaurantTable>().WithMany().HasForeignKey(a => a.TableId)
            .OnDelete(DeleteBehavior.Restrict);
    }
}

internal sealed class ServiceAssignmentHistoryConfiguration : IEntityTypeConfiguration<ServiceAssignmentHistory>
{
    public void Configure(EntityTypeBuilder<ServiceAssignmentHistory> builder)
    {
        builder.ToTable("ServiceAssignmentHistory");
        builder.HasKey(h => h.Id);

        builder.Property(h => h.Action).HasEnumStringConversion().IsRequired();
        builder.Property(h => h.Reason).HasMaxLength(300);

        builder.HasIndex(h => new { h.RestaurantId, h.TableId, h.ChangedAt })
            .HasDatabaseName("IX_ServiceAssignmentHistory_Restaurant_Table_ChangedAt");

        builder.HasIndex(h => new { h.RestaurantId, h.ChangedAt })
            .HasDatabaseName("IX_ServiceAssignmentHistory_Restaurant_ChangedAt");

        // No FK on PreviousWaiterId / NewWaiterId / ChangedBy on purpose: this
        // table must remain readable even if a staff row is ever archived, and
        // it must never be the reason a write is blocked. It is evidence, not a
        // relational hub.
    }
}
