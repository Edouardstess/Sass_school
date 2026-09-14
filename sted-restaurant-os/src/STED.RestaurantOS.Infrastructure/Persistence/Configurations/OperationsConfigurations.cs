using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using STED.RestaurantOS.Domain.Auditing;
using STED.RestaurantOS.Domain.Billing;
using STED.RestaurantOS.Domain.Floor;
using STED.RestaurantOS.Domain.Notifications;
using STED.RestaurantOS.Domain.Ordering;
using STED.RestaurantOS.Domain.Preparation;
using STED.RestaurantOS.Domain.Restaurants;
using STED.RestaurantOS.Infrastructure.Idempotency;

namespace STED.RestaurantOS.Infrastructure.Persistence.Configurations;

internal sealed class PreparationTicketConfiguration : IEntityTypeConfiguration<PreparationTicket>
{
    public void Configure(EntityTypeBuilder<PreparationTicket> builder)
    {
        builder.ToTable("PreparationTickets");
        builder.HasKey(t => t.Id);

        builder.Property(t => t.TicketNumber).HasMaxLength(24).IsUnicode(false).IsRequired();
        builder.Property(t => t.Status).HasEnumStringConversion().IsRequired();
        builder.Property(t => t.RowVersion).IsRowVersion();

        // The query every kitchen and bar screen runs, all day, every 15 seconds.
        builder.HasIndex(t => new { t.RestaurantId, t.StationId, t.Status, t.CreatedAtUtc })
            .HasDatabaseName("IX_PreparationTickets_Restaurant_Station_Status_CreatedAt");

        builder.HasIndex(t => t.OrderId).HasDatabaseName("IX_PreparationTickets_Order");

        builder.HasOne<Order>().WithMany().HasForeignKey(t => t.OrderId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.HasMany(t => t.Items)
            .WithOne()
            .HasForeignKey(i => i.PreparationTicketId)
            .OnDelete(DeleteBehavior.Cascade);

        builder.Metadata.FindNavigation(nameof(PreparationTicket.Items))!
            .SetPropertyAccessMode(PropertyAccessMode.Field);

        builder.Ignore(t => t.DomainEvents);
        builder.Ignore(t => t.IsOpen);
    }
}

internal sealed class PreparationTicketItemConfiguration : IEntityTypeConfiguration<PreparationTicketItem>
{
    public void Configure(EntityTypeBuilder<PreparationTicketItem> builder)
    {
        builder.ToTable("PreparationTicketItems");
        builder.HasKey(i => i.Id);

        builder.Property(i => i.ProductNameSnapshot).HasMaxLength(160).IsRequired();
        builder.Property(i => i.Notes).HasMaxLength(300);
        builder.Property(i => i.ModifiersSummary).HasMaxLength(500);
        builder.Property(i => i.Status).HasEnumStringConversion().IsRequired();
    }
}

internal sealed class PaymentConfiguration : IEntityTypeConfiguration<Payment>
{
    public void Configure(EntityTypeBuilder<Payment> builder)
    {
        builder.ToTable("Payments");
        builder.HasKey(p => p.Id);

        builder.Property(p => p.Method).HasEnumStringConversion().IsRequired();
        builder.Property(p => p.Status).HasEnumStringConversion().IsRequired();
        builder.Property(p => p.Currency).HasMaxLength(3).IsFixedLength().IsUnicode(false).IsRequired();
        builder.Property(p => p.TransactionReference).HasMaxLength(120);
        builder.Property(p => p.FailureReason).HasMaxLength(300);
        builder.Property(p => p.IdempotencyKey).HasMaxLength(80).IsUnicode(false).IsRequired();

        builder.OwnsOne(p => p.Amount, m => m.ConfigureMoney("Amount"));
        builder.Navigation(p => p.Amount).IsRequired();

        // The hard stop against double charging: the same key can only ever
        // produce one payment row in one restaurant.
        builder.HasIndex(p => new { p.RestaurantId, p.IdempotencyKey })
            .IsUnique()
            .HasDatabaseName("UX_Payments_Restaurant_IdempotencyKey");

        builder.HasIndex(p => new { p.RestaurantId, p.PaidAt })
            .HasDatabaseName("IX_Payments_Restaurant_PaidAt");

        builder.HasIndex(p => p.OrderId).HasDatabaseName("IX_Payments_Order");

        builder.HasOne<Order>().WithMany().HasForeignKey(p => p.OrderId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.HasOne<TableSession>().WithMany().HasForeignKey(p => p.TableSessionId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.Ignore(p => p.DomainEvents);
        builder.Ignore(p => p.CountsTowardsSettlement);
    }
}

internal sealed class AuditLogConfiguration : IEntityTypeConfiguration<AuditLog>
{
    public void Configure(EntityTypeBuilder<AuditLog> builder)
    {
        builder.ToTable("AuditLogs");

        // Identity bigint, not a Guid: this is the highest-volume table in the
        // system and it is append-only, so a narrow clustered key wins.
        builder.HasKey(a => a.Id);
        builder.Property(a => a.Id).ValueGeneratedOnAdd();

        builder.Property(a => a.Action).HasMaxLength(60).IsUnicode(false).IsRequired();
        builder.Property(a => a.EntityName).HasMaxLength(60).IsUnicode(false).IsRequired();
        builder.Property(a => a.EntityId).HasMaxLength(60).IsUnicode(false);
        builder.Property(a => a.IpAddress).HasMaxLength(45).IsUnicode(false);
        builder.Property(a => a.UserAgent).HasMaxLength(300);
        builder.Property(a => a.CorrelationId).HasMaxLength(60).IsUnicode(false);

        builder.HasIndex(a => new { a.RestaurantId, a.CreatedAt })
            .HasDatabaseName("IX_AuditLogs_Restaurant_CreatedAt");

        builder.HasIndex(a => new { a.RestaurantId, a.EntityName, a.EntityId })
            .HasDatabaseName("IX_AuditLogs_Restaurant_Entity");

        // One incident, one query: everything that happened under one request id.
        builder.HasIndex(a => a.CorrelationId)
            .HasDatabaseName("IX_AuditLogs_CorrelationId");
    }
}

internal sealed class NotificationConfiguration : IEntityTypeConfiguration<Notification>
{
    public void Configure(EntityTypeBuilder<Notification> builder)
    {
        builder.ToTable("Notifications");
        builder.HasKey(n => n.Id);

        builder.Property(n => n.TargetType).HasEnumStringConversion(12).IsRequired();
        builder.Property(n => n.TargetId).HasMaxLength(60).IsUnicode(false).IsRequired();
        builder.Property(n => n.Type).HasMaxLength(40).IsUnicode(false).IsRequired();
        builder.Property(n => n.Title).HasMaxLength(160).IsRequired();
        builder.Property(n => n.Body).HasMaxLength(500);

        builder.HasIndex(n => new { n.RestaurantId, n.TargetType, n.TargetId, n.IsRead, n.CreatedAt })
            .HasDatabaseName("IX_Notifications_Restaurant_Target_Unread");

        builder.HasOne<Restaurant>().WithMany().HasForeignKey(n => n.RestaurantId)
            .OnDelete(DeleteBehavior.Restrict);

        builder.Ignore(n => n.DomainEvents);
    }
}

internal sealed class IdempotencyRecordConfiguration : IEntityTypeConfiguration<IdempotencyRecord>
{
    public void Configure(EntityTypeBuilder<IdempotencyRecord> builder)
    {
        builder.ToTable("IdempotencyRecords");
        builder.HasKey(r => r.Id);

        builder.Property(r => r.Key).HasMaxLength(80).IsUnicode(false).IsRequired();
        builder.Property(r => r.Endpoint).HasMaxLength(200).IsUnicode(false).IsRequired();
        builder.Property(r => r.RequestHash).HasColumnType("varbinary(32)").IsRequired();

        builder.HasIndex(r => new { r.RestaurantId, r.Key, r.Endpoint })
            .IsUnique()
            .HasDatabaseName("UX_IdempotencyRecords_Restaurant_Key_Endpoint");

        builder.HasIndex(r => r.ExpiresAt).HasDatabaseName("IX_IdempotencyRecords_ExpiresAt");
    }
}
