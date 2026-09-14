using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Shared;

namespace STED.RestaurantOS.Domain.Notifications;

public enum NotificationTargetType
{
    User = 0,
    Role = 1,
    Station = 2,
}

/// <summary>
/// A persisted notification. SignalR delivers the live nudge; this row is what
/// survives a dropped connection, a reload, or a phone that was in a pocket.
/// </summary>
public sealed class Notification : AuditableAggregateRoot, ITenantEntity
{
    private Notification()
    {
    }

    private Notification(
        Guid id,
        Guid restaurantId,
        NotificationTargetType targetType,
        string targetId,
        string type,
        string title,
        string? body,
        string? payloadJson)
        : base(id)
    {
        RestaurantId = restaurantId;
        TargetType = targetType;
        TargetId = targetId;
        Type = type;
        Title = title;
        Body = body;
        PayloadJson = payloadJson;
        IsRead = false;
    }

    public Guid RestaurantId { get; private set; }

    public NotificationTargetType TargetType { get; private set; }

    public string TargetId { get; private set; } = null!;

    public string Type { get; private set; } = null!;

    public string Title { get; private set; } = null!;

    public string? Body { get; private set; }

    public string? PayloadJson { get; private set; }

    public bool IsRead { get; private set; }

    public DateTimeOffset? ReadAt { get; private set; }

    public static Notification Create(
        Guid restaurantId,
        NotificationTargetType targetType,
        string targetId,
        string type,
        string title,
        string? body = null,
        string? payloadJson = null)
    {
        Guard.NotEmpty(restaurantId);
        Guard.NotNullOrWhiteSpace(targetId);
        Guard.NotNullOrWhiteSpace(type);
        Guard.NotNullOrWhiteSpace(title);

        return new Notification(
            Guid.CreateVersion7(),
            restaurantId,
            targetType,
            Guard.MaxLength(targetId, 60),
            Guard.MaxLength(type, 40),
            Guard.MaxLength(title, 160),
            body,
            payloadJson);
    }

    public void MarkRead(DateTimeOffset at)
    {
        if (IsRead)
        {
            return;
        }

        IsRead = true;
        ReadAt = at;
    }
}
