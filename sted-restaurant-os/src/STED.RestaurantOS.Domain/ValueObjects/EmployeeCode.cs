using System.Text.RegularExpressions;
using STED.RestaurantOS.Domain.Common;
using STED.RestaurantOS.Domain.Exceptions;

namespace STED.RestaurantOS.Domain.ValueObjects;

/// <summary>
/// Short human code a staff member can type on a phone during service
/// (uppercase alphanumeric, 3 to 10 characters). Unique per restaurant.
/// </summary>
public sealed partial class EmployeeCode : ValueObject
{
    public const int MaxLength = 10;

    private EmployeeCode(string value) => Value = value;

    public string Value { get; }

    public static EmployeeCode Of(string value)
    {
        var normalised = (value ?? string.Empty).Trim().ToUpperInvariant();
        if (!Pattern().IsMatch(normalised))
        {
            throw new DomainValidationException(
                "Employee code must be 3 to 10 uppercase letters or digits.");
        }

        return new EmployeeCode(normalised);
    }

    public override string ToString() => Value;

    protected override IEnumerable<object?> GetEqualityComponents()
    {
        yield return Value;
    }

    [GeneratedRegex("^[A-Z0-9]{3,10}$")]
    private static partial Regex Pattern();
}
