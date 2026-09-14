# STED Restaurant OS

SaaS operational platform for restaurants, bars, lounges and hotel restaurants.
Guests order from their table by scanning a QR code; the kitchen and the bar see
the order immediately, split per station; the venue keeps a complete, auditable
record of who served what, when, and to which table.

**Status: Phase 2 of 20 — Database & Domain.**
The architectural analysis is in
[`../docs/sted-restaurant-os/PHASE-1-ANALYSE.md`](../docs/sted-restaurant-os/PHASE-1-ANALYSE.md).

---

## What exists today

| Layer | State |
|---|---|
| `Domain` | Complete for the MVP scope: aggregates, value objects, enums, domain events, business rules |
| `Infrastructure/Persistence` | `AppDbContext`, all EF Core configurations, indexes, constraints, tenant interceptors |
| `Shared` | Error codes, guards, paging contracts |
| `UnitTests` | Domain rules, money arithmetic, traceability, order lifecycle, architecture rules |
| `Application` | Phase 3+ (empty) |
| `API` | Phase 3+ (empty) |
| Migrations | **Not generated yet** — see below |
| Frontend | Phase 14 |

## Build and run

Requires the .NET 10 SDK and SQL Server 2022 (or the Docker image).

```bash
dotnet restore
dotnet build
dotnet test
```

### Creating the database

The EF Core migration has not been generated yet, because the environment this
code was authored in had no .NET SDK available (see *Authoring constraints*
below). Generating it is the first command to run locally:

```bash
dotnet tool install --global dotnet-ef

dotnet ef migrations add InitialCreate \
  --project src/STED.RestaurantOS.Infrastructure \
  --startup-project src/STED.RestaurantOS.Infrastructure

dotnet ef database update
```

Until the API project exists (Phase 3), a design-time factory or a startup
project is needed for `dotnet ef`; the API will provide it.

> Migrations are applied by a deployment step, never by the application at
> startup: two instances migrating in parallel corrupt a database.

## Authoring constraints — read this before reviewing

This phase was written in an environment whose network policy allows only the
npm and PyPI registries. Microsoft's download hosts are blocked, so **no .NET SDK
and no SQL Server could be installed**, and therefore:

- the code has **not been compiled**;
- the tests have **not been executed**;
- no EF Core migration could be generated.

What *was* verified: every one of the 77 C# files was parsed with the
`tree-sitter-c-sharp` grammar and is syntactically valid. That catches typos and
malformed constructs. It does **not** catch type errors, wrong overloads, or EF
Core mapping mistakes.

Expect to run `dotnet build` and fix a handful of compile errors before the first
green test run. Treat this phase as a reviewed design in code form, not as
verified software.

## Decisions taken in this phase

Where Phase 1 left a choice open, these are the calls made and why.

| Decision | Choice | Reason |
|---|---|---|
| Repository placement | Subfolder of the existing repo | The repo already hosts an unrelated product (SchoolFlow); a subfolder keeps both intact and is reversible. A dedicated repository is still the better long-term home |
| Default currency | `HTG`, `decimal(18,2)` | Haitian venues; exact base-10 arithmetic |
| Guest order confirmation | Direct to the kitchen by default | The product exists to stop the kitchen waiting for a waiter. Venues that want a human gate flip `OrderRequiresWaiterConfirmation` |
| Payment methods | `CASH` only, others modelled | Matches the brief; the schema and enums already carry the rest |
| Entity identifiers | Plain `Guid` (v7, sequential) | **Departs from the Phase 1 recommendation of strongly-typed ids.** Without a compiler, adding ~15 id types plus their EF converters would multiply the chance of shipping a broken build. It is a mechanical, compiler-guided refactor — better done in a phase where the compiler can check every call site |
| `Money` mapping | EF owned type, amount + currency columns | A value converter cannot read sibling properties, so it could not rebuild `Money` from a currency stored elsewhere. Slightly redundant with `Order.Currency`; keeps the value object self-validating |
| Identity tables | Phase 3 | `StaffProfile.UserId` therefore has no foreign key yet |

## Where the important decisions live in the code

If you read only four files, read these:

| File | Why |
|---|---|
| `Domain/Floor/TableSession.cs` | Owns the service assignments, so a table cannot change hands without writing its audit trail |
| `Domain/Ordering/Order.cs` | Computes every amount server-side; freezes `WaiterId` at confirmation |
| `Infrastructure/Persistence/Configurations/FloorConfigurations.cs` | The filtered unique indexes that make the concurrency guarantees real |
| `Infrastructure/Persistence/Interceptors/TenantGuardInterceptor.cs` | The write-path half of multi-tenant isolation |

## Next phase

Phase 3 — Authentication & Authorization: ASP.NET Identity, JWT with refresh
token rotation, granular permissions, the HTTP-scoped `ITenantContext`, and the
integration tests that prove one restaurant cannot read another's data.
