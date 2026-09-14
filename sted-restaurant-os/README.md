# STED Restaurant OS

SaaS operational platform for restaurants, bars, lounges and hotel restaurants.

Guests order from their table by scanning a QR code. The kitchen and the bar see
the order immediately, split by station. The venue keeps a complete, auditable
record of who served what, when, and to which table.

The problem it solves: in a busy room the waiter is the bottleneck. A guest waits
for someone to be free while the kitchen stands idle. This decouples *placing*
an order from *a waiter being available*, without giving up any of the
traceability a manager needs afterwards.

---

## Status

| Phase | Scope | State |
|:-:|---|---|
| 1 | Architectural analysis | Done — [`../docs/sted-restaurant-os/PHASE-1-ANALYSE.md`](../docs/sted-restaurant-os/PHASE-1-ANALYSE.md) |
| 2 | Domain + EF Core persistence | Written, **not compiled** |
| 3 | Identity, JWT, permissions, tenancy | Written, **not compiled** |
| 5–9 | QR sessions, floor, menu, order engine, KDS/BDS | Written, **not compiled** |
| 11–12 | Cashier, payments, reporting | Written, **not compiled** |
| 14 | Frontend | **Verified** — typechecks, 14 tests pass, builds |
| 16–17 | Docker, nginx, CI | Written |
| 19 | Documentation | Done |
| 4, 10, 13, 15, 18, 20 | Staff admin CRUD, integration tests, optimisation, production | Not started |

### Why "not compiled"

This code was authored in an environment whose network policy allows only the
npm and PyPI registries. Microsoft's download hosts are blocked, so **no .NET SDK
and no SQL Server could be installed**. The backend has therefore never been
through a compiler, and no EF Core migration exists yet.

What *was* verified on the backend: all 147 C# files parse cleanly under the
`tree-sitter-c-sharp` grammar. That catches typos and malformed constructs. It
does not catch type errors, wrong overloads, or EF mapping mistakes.

The frontend had no such limitation, and is genuinely verified.

**Expect to spend a session fixing compile errors before the first green test
run.** Treat the backend as a reviewed design in code form, not as working
software.

---

## Getting it running

```bash
cp .env.example .env          # fill in SQL_PASSWORD and JWT__SIGNINGKEY
docker compose up --build
```

Or locally:

```bash
# Backend
dotnet restore && dotnet build && dotnet test

dotnet tool install --global dotnet-ef
dotnet ef migrations add InitialCreate \
  --project src/STED.RestaurantOS.Infrastructure \
  --startup-project src/STED.RestaurantOS.API
dotnet ef database update --startup-project src/STED.RestaurantOS.API

dotnet run --project src/STED.RestaurantOS.API

# Frontend
cd frontend/sted-restaurant-os-web
npm install && npm run dev
```

Migrations are applied by a deployment step, never at application startup: two
instances migrating in parallel corrupt a database.

Generate the signing key, do not invent one:

```bash
openssl rand -base64 48
```

---

## Documentation

| Document | What it covers |
|---|---|
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Layers, aggregates, the two decisions everything rests on |
| [DATABASE.md](docs/DATABASE.md) | Schema, the five indexes that carry the system, volumetry |
| [API.md](docs/API.md) | Endpoints, envelope, error codes, idempotency |
| [SECURITY.md](docs/SECURITY.md) | Tenancy, tokens, permissions, OWASP coverage |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | Environments, secrets, migrations, monitoring |
| [PHASE-2-NOTES.md](docs/PHASE-2-NOTES.md) | What was built in the domain phase, and what to challenge |

---

## The four files worth reading first

| File | Why |
|---|---|
| `Domain/Floor/TableSession.cs` | Owns the service assignments, so a table cannot change hands without writing its audit trail |
| `Domain/Ordering/Order.cs` | Computes every amount server-side; freezes the responsible waiter at confirmation |
| `Infrastructure/Persistence/Configurations/FloorConfigurations.cs` | The filtered unique indexes that make the concurrency guarantees real |
| `Infrastructure/Services/OrderService.cs` | The transaction the whole product depends on |

---

## Decisions taken along the way

| Decision | Choice | Reason |
|---|---|---|
| Repository placement | Subfolder of this repo | It already hosts an unrelated product (SchoolFlow). A subfolder keeps both intact and is reversible; a dedicated repository is still the better long-term home |
| Default currency | `HTG`, `decimal(18,2)` | Haitian venues; exact base-10 arithmetic, because binary rounding on repeated additions becomes a real cash-drawer discrepancy |
| Guest confirmation | Straight to the kitchen | The product exists to stop the kitchen waiting for a waiter. Venues wanting a human gate flip `OrderRequiresWaiterConfirmation` |
| Payment methods | `CASH` only, others modelled | Matches the brief; adding MonCash means writing a provider, not migrating the database |
| Entity identifiers | Plain `Guid` v7 | **Departs from the Phase 1 recommendation of strongly-typed ids** — see PHASE-2-NOTES.md §3 |
| Warnings as errors | Off, for now | Turn on once the first build is green, so real errors are not buried in style warnings |

---

## What is deliberately not here

Event sourcing, microservices, a separate read database, GraphQL, Kubernetes.
Each was considered and rejected in the Phase 1 analysis (§30.3); none of them
earns its complexity at this scale, and several would actively hurt — microservices
would destroy the atomicity of order, tickets and history that the whole audit
trail depends on.
