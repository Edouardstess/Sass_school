using System.Text.Json;
using System.Text.Json.Serialization;

namespace STED.RestaurantOS.API.Contracts;

/// <summary>
/// One envelope for every response, success or failure.
/// <para>
/// A single shape means the frontend has one place that decides whether a call
/// worked, instead of one per endpoint. <c>code</c> is what client code
/// switches on; <c>message</c> is for humans and may be reworded freely.
/// </para>
/// </summary>
public sealed record ApiResponse
{
    public bool Success { get; init; }

    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public object? Data { get; init; }

    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? Message { get; init; }

    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? Code { get; init; }

    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public IReadOnlyDictionary<string, string[]>? Errors { get; init; }

    public string? TraceId { get; init; }

    /// <summary>Populated in development only; never in production.</summary>
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? Detail { get; init; }

    public static ApiResponse Ok(object? data, string? traceId = null)
        => new() { Success = true, Data = data, TraceId = traceId };

    public static ApiResponse Fail(
        string message,
        string code,
        string? traceId = null,
        string? detail = null,
        IReadOnlyDictionary<string, string[]>? errors = null)
        => new()
        {
            Success = false,
            Message = message,
            Code = code,
            TraceId = traceId,
            Detail = detail,
            Errors = errors,
        };
}

public static class JsonDefaults
{
    public static JsonSerializerOptions Options { get; } = new(JsonSerializerDefaults.Web)
    {
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
        Converters = { new JsonStringEnumConverter() },
    };
}
