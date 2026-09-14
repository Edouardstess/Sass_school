using STED.RestaurantOS.Domain.Common;

namespace STED.RestaurantOS.UnitTests.Fixtures;

/// <summary>
/// A clock the test drives. Service rules are time-sensitive (who was serving at
/// 20:15, is this ticket late), so time has to be an input, never a hidden call
/// to DateTimeOffset.UtcNow.
/// </summary>
public sealed class TestClock : IDateTimeProvider
{
    public TestClock(DateTimeOffset start) => UtcNow = start;

    public DateTimeOffset UtcNow { get; private set; }

    public static TestClock AtServiceEvening()
        => new(new DateTimeOffset(2026, 3, 14, 19, 0, 0, TimeSpan.Zero));

    public DateTimeOffset Advance(TimeSpan by)
    {
        UtcNow = UtcNow.Add(by);
        return UtcNow;
    }

    public DateTimeOffset AdvanceMinutes(int minutes) => Advance(TimeSpan.FromMinutes(minutes));
}
