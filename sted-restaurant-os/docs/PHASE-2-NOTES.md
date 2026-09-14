# Phase 2 — Database & Domain: implementation notes

Companion to `docs/sted-restaurant-os/PHASE-1-ANALYSE.md`. It records what was
built, what deviates from the analysis, and what a reviewer should challenge.

---

## 1. What was built

77 C# files across four projects.

```
src/STED.RestaurantOS.Shared/          error codes, guards, paging
src/STED.RestaurantOS.Domain/          11 aggregates, 6 value objects, 20 domain events
src/STED.RestaurantOS.Infrastructure/  DbContext, 20 EF configurations, 2 interceptors
tests/STED.RestaurantOS.UnitTests/     ~60 tests over the rules that matter
```

### Aggregates

| Aggregate | Owns | Invariant it exists to protect |
|---|---|---|
| `Restaurant` | `RestaurantSettings` | One settings row per venue (enforced by making the FK the PK) |
| `StaffProfile` | — | Unique employee code per venue |
| `TableZone` | — | Unique name per venue |
| `RestaurantTable` | `TableQrCode` | At most one active QR code |
| **`TableSession`** | `ServiceAssignment`, `ServiceAssignmentHistory` | **At most one active waiter; every hand-over writes history** |
| `Station`, `MenuCategory` | — | Unique code / name per venue |
| `Product` | `ProductModifier`, `ModifierOption` | `min <= max`, station mandatory, price >= 0 |
| **`Order`** | `OrderItem`, `OrderItemModifier`, `OrderStatusHistory` | **Totals derive from lines; legal transitions only; frozen waiter** |
| `PreparationTicket` | `PreparationTicketItem` | Forward-only station workflow |
| `Payment` | — | Positive amount; sum never exceeds the order total |
| `AuditLog` | — | Append-only: the type has no mutator at all |

## 2. The two structural decisions, in code

### `ServiceAssignment` lives inside `TableSession`

`TableSession.TransferTo(...)` is the only way to hand a table over, and it
writes the history row in the same method body. There is no code path that
changes the responsible waiter without recording it — traceability is a property
of the type, not a convention.

The database repeats the guarantee:

```
UX_ServiceAssignments_ActiveBySession  UNIQUE (TableSessionId) WHERE Status = 'Active'
UX_TableSessions_OpenByTable           UNIQUE (TableId) WHERE Status IN ('Open','Active')
```

Application code cannot win a race on its own. These indexes can. A lost race
surfaces as a unique-violation that the API turns into
`409 TABLE_ALREADY_ASSIGNED` — never as two waiters owning one table.

### `Order.WaiterId` is frozen, the assignment chain is live

`Order.Confirm(waiterId, ...)` takes the waiter once and never reassigns it.
`WaiterAttributionTests` walks the exact scenario from the brief (Jean 19:00,
order 19:15, transfer 19:45, order 20:00) and asserts the first order still
belongs to Jean afterwards.

## 3. Deviations from Phase 1

| # | Analysis said | Built | Why |
|---|---|---|---|
| 1 | Strongly-typed ids (`OrderId`, `TableId`…) | Plain `Guid` | No compiler available in the authoring environment; ~15 id types plus EF converters is exactly the kind of change that needs compiler feedback. Deferred to a phase where every call site can be checked |
| 2 | One `Currency` column per order | Currency column beside each `Money` | EF value converters cannot read sibling properties. The redundancy buys a self-validating value object |
| 3 | Enums as constrained strings | Enums stored as their **names** | Same effect, plus reordering an enum can never silently reinterpret existing rows. Filtered index predicates use the names (`'Active'`, `'Open'`) |
| 4 | FKs on all actor columns | FK only on `WaiterId` | `CreatedBy`, `ChangedBy`, `ProcessedBy` may reference a platform admin with no staff profile, and five FKs from `Orders` to `StaffProfiles` create multiple cascade paths SQL Server rejects. History must never be the reason a write fails |

## 4. Verification actually performed

| Check | Result |
|---|---|
| C# syntax, all 77 files (`tree-sitter-c-sharp`) | Pass |
| `dotnet build` | **Not run** — no SDK (egress policy blocks Microsoft hosts) |
| `dotnet test` | **Not run** — same reason |
| EF migration generation | **Not run** — same reason |

Two defects were found and fixed during authoring, both by reasoning rather than
by a compiler:

- `HasEnumStringConversion` was applied to the nullable
  `OrderStatusHistory.PreviousStatus`; the generic constraint `where TEnum :
  struct, Enum` would not have matched. A nullable overload was added.
- `ToTable(name)` followed by `ToTable(builder)` was replaced with the single
  two-argument overload on five entities, to avoid relying on the second call
  preserving the table name.

Others of the same kind are likely to remain. The first `dotnet build` is the
real review.

## 5. What a reviewer should challenge

1. **Owned `Money` types.** Five extra `char(3)` columns on `Orders`. Worth it?
2. **`PreparationTicket` as a separate aggregate.** It buys no contention
   between cooks and guests at the cost of eventual consistency on the order
   status. Is sub-second convergence acceptable on a KDS?
3. **`Close(..., bool hasUnsettledOrders)`.** The caller supplies the fact
   because the aggregate must not query the database. Honest, or a leak?
4. **Tax-inclusive pricing.** Implemented in `OrderItem.Recalculate`. Does it
   match how Haitian venues actually display prices?
5. **`Guid.CreateVersion7()`** — .NET 9+. Confirm it is available on the
   deployment target.

## 6. Next steps

1. Run `dotnet build` and fix what the compiler finds.
2. Run `dotnet test`; the unit tests should pass without touching a database.
3. Generate `InitialCreate` and review the SQL against the MLD in §22 of the
   Phase 1 analysis.
4. Then Phase 3 — Identity, JWT, permissions, and the tenant-isolation
   integration tests.
