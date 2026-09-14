using System.Text.RegularExpressions;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;

namespace STED.RestaurantOS.Domain.ValueObjects;

/// <summary>URL-safe public identifier of a restaurant. Globally unique.</summary>
public sealed partial class Slug : ValueObject
{
    public const int MaxLength = 60;

    private Slug(string value) => Value = value;

    public string Value { get; }

    public static Slug Of(string value)
    {
        var normalised = (value ?? string.Empty).Trim().ToLowerInvariant();
        if (!Pattern().IsMatch(normalised))
        {
            throw new DomainValidationException(
                "Slug must be 3 to 60 lowercase letters, digits or hyphens, and cannot start or end with a hyphen.");
        }

        return new Slug(normalised);
    }

    public override string ToString() => Value;

    protected override IEnumerable<object?> GetEqualityComponents()
    {
        yield return Value;
    }

    [GeneratedRegex("^[a-z0-9](?:[a-z0-9-]{1,58}[a-z0-9])$")]
    private static partial Regex Pattern();
}
