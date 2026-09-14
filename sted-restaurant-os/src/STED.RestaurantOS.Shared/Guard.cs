using System.Runtime.CompilerServices;

namespace STED.RestaurantOS.Shared;

/// <summary>
/// Minimal argument guards. Deliberately tiny: these protect constructor
/// invariants, they are not a validation framework (that is FluentValidation,
/// at the application boundary).
/// </summary>
public static class Guard
{
    public static T NotNull<T>(T? value, [CallerArgumentExpression(nameof(value))] string? name = null)
        where T : class
        => value ?? throw new ArgumentNullException(name);

    public static string NotNullOrWhiteSpace(
        string? value,
        [CallerArgumentExpression(nameof(value))] string? name = null)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            throw new ArgumentException("Value cannot be null or whitespace.", name);
        }

        return value;
    }

    public static string MaxLength(
        string value,
        int maxLength,
        [CallerArgumentExpression(nameof(value))] string? name = null)
    {
        if (value.Length > maxLength)
        {
            throw new ArgumentException($"Value cannot exceed {maxLength} characters.", name);
        }

        return value;
    }

    public static Guid NotEmpty(Guid value, [CallerArgumentExpression(nameof(value))] string? name = null)
    {
        if (value == Guid.Empty)
        {
            throw new ArgumentException("Identifier cannot be empty.", name);
        }

        return value;
    }

    public static int InRange(
        int value,
        int min,
        int max,
        [CallerArgumentExpression(nameof(value))] string? name = null)
    {
        if (value < min || value > max)
        {
            throw new ArgumentOutOfRangeException(name, value, $"Value must be between {min} and {max}.");
        }

        return value;
    }

    public static decimal NotNegative(
        decimal value,
        [CallerArgumentExpression(nameof(value))] string? name = null)
    {
        if (value < 0m)
        {
            throw new ArgumentOutOfRangeException(name, value, "Value cannot be negative.");
        }

        return value;
    }
}
