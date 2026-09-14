using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Domain.Exceptions;

/// <summary>
/// Base of every deliberate refusal coming from the domain. Carries a stable
/// error code so the API layer can map it to an HTTP status without string
/// matching on messages.
/// </summary>
public abstract class DomainException : Exception
{
    protected DomainException(string code, string message)
        : base(message)
        => Code = code;

    public string Code { get; }
}

/// <summary>A business rule said no. Maps to HTTP 409 by default.</summary>
public sealed class BusinessRuleViolationException : DomainException
{
    public BusinessRuleViolationException(string code, string message)
        : base(code, message)
    {
    }
}

/// <summary>An argument was structurally invalid. Maps to HTTP 400.</summary>
public sealed class DomainValidationException : DomainException
{
    public DomainValidationException(string message)
        : base(ErrorCodes.ValidationError, message)
    {
    }

    public DomainValidationException(string code, string message)
        : base(code, message)
    {
    }
}
