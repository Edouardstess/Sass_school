using STED.RestaurantOS.Shared.Errors;

namespace STED.RestaurantOS.Application.Common;

/// <summary>
/// The outcome of a use case: either a value, or a business failure with a code
/// the API can map to an HTTP status.
/// <para>
/// Expected failures — wrong password, table already taken — are values, not
/// exceptions. Exceptions stay for what is genuinely exceptional, which keeps
/// stack traces meaningful and the happy path cheap.
/// </para>
/// </summary>
public readonly record struct Error(string Code, string Message)
{
    public static Error Validation(string message) => new(ErrorCodes.ValidationError, message);

    public static Error NotFound(string message) => new(ErrorCodes.NotFound, message);

    public static Error Unauthorized(string message) => new(ErrorCodes.Unauthorized, message);

    public static Error Forbidden(string message) => new(ErrorCodes.Forbidden, message);

    public static Error Conflict(string code, string message) => new(code, message);
}

public readonly record struct Result
{
    private Result(bool isSuccess, Error? error)
    {
        IsSuccess = isSuccess;
        Error = error;
    }

    public bool IsSuccess { get; }

    public bool IsFailure => !IsSuccess;

    public Error? Error { get; }

    public static Result Success() => new(true, null);

    public static Result Failure(Error error) => new(false, error);
}

public readonly record struct Result<T>
{
    private Result(bool isSuccess, T? value, Error? error)
    {
        IsSuccess = isSuccess;
        Value = value;
        Error = error;
    }

    public bool IsSuccess { get; }

    public bool IsFailure => !IsSuccess;

    public T? Value { get; }

    public Error? Error { get; }

    public static Result<T> Success(T value) => new(true, value, null);

    public static Result<T> Failure(Error error) => new(false, default, error);

    public static implicit operator Result<T>(T value) => Success(value);

    public static implicit operator Result<T>(Error error) => Failure(error);
}
