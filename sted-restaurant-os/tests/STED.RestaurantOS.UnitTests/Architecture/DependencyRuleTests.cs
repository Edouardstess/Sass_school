using System.Reflection;
using FluentAssertions;
using STED.RestaurantOS.Domain.Ordering;
using Xunit;

namespace STED.RestaurantOS.UnitTests.Architecture;

/// <summary>
/// The dependency rule, enforced by a test rather than by good intentions.
/// <para>
/// A modular monolith stops being modular about three weeks after someone adds
/// "just one" framework reference to the domain. This test fails the build when
/// that happens.
/// </para>
/// </summary>
public sealed class DependencyRuleTests
{
    private static readonly string[] ForbiddenInDomain =
    [
        "Microsoft.EntityFrameworkCore",
        "Microsoft.AspNetCore",
        "Microsoft.Extensions.DependencyInjection",
        "Newtonsoft.Json",
        "AutoMapper",
        "FluentValidation",
        "Serilog",
    ];

    [Fact]
    public void The_domain_depends_on_no_framework_at_all()
    {
        var domain = typeof(Order).Assembly;

        var offenders = domain
            .GetReferencedAssemblies()
            .Select(a => a.Name ?? string.Empty)
            .Where(name => ForbiddenInDomain.Any(f => name.StartsWith(f, StringComparison.Ordinal)))
            .ToList();

        offenders.Should().BeEmpty(
            "the domain must stay independent of persistence, transport and DI");
    }

    [Fact]
    public void The_domain_references_nothing_of_ours_except_shared()
    {
        var ourReferences = typeof(Order).Assembly
            .GetReferencedAssemblies()
            .Select(a => a.Name ?? string.Empty)
            .Where(name => name.StartsWith("STED.", StringComparison.Ordinal))
            .ToList();

        ourReferences.Should().OnlyContain(name => name == "STED.RestaurantOS.Shared");
    }

    /// <summary>
    /// Aggregates keep their collections private: exposing a mutable list would
    /// let callers bypass the very methods that protect the invariants.
    /// </summary>
    [Fact]
    public void No_aggregate_exposes_a_mutable_collection()
    {
        var domain = typeof(Order).Assembly;

        var leaks = domain.GetTypes()
            .Where(t => t.IsClass && !t.IsAbstract)
            .SelectMany(t => t.GetProperties(BindingFlags.Public | BindingFlags.Instance))
            .Where(p => p.PropertyType.IsGenericType
                        && p.PropertyType.GetGenericTypeDefinition() == typeof(List<>))
            .Select(p => $"{p.DeclaringType?.Name}.{p.Name}")
            .ToList();

        leaks.Should().BeEmpty();
    }
}
