using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Infrastructure.Tenancy;

/// <summary>
/// Thrown when a write would cross a restaurant boundary. Always a bug or an
/// attack, never a normal outcome — the API maps it to 404, never to a message
/// that would confirm the other restaurant's data exists.
/// </summary>
public sealed class TenantViolationException : Exception
{
    public TenantViolationException(string message)
        : base(message)
    {
    }

    public string Code => ErrorCodes.TenantViolation;
}
