# Database

SQL Server 2022. Single database, shared schema, `RestaurantId` discriminator.

---

## The five indexes that carry the system

Everything else is ordinary. These five are load-bearing.

| Index | Serves | Why it matters |
|---|---|---|
| `UX_ServiceAssignments_ActiveBySession` UNIQUE (`TableSessionId`) WHERE `Status='Active'` | One waiter per table | The ultimate guarantee. Two waiters tapping at the same instant: the database picks one, the other gets `409`. Without this, application code would have to win a race it cannot win |
| `UX_TableSessions_OpenByTable` UNIQUE (`TableId`) WHERE `Status IN ('Open','Active')` | One party per table | Six phones scanning at once converge on one session instead of six separate bills |
| `IX_PreparationTickets_Restaurant_Station_Status_CreatedAt` | Every KDS screen | Runs every fifteen seconds per screen, all service, every day. The single hottest read in the system |
| `IX_Orders_Restaurant_Waiter_CreatedAt` INCLUDE (`Status`) | Waiter reports | Covering: the aggregation never touches the table itself |
| `IX_ServiceAssignments_Restaurant_Table_Window` (`AssignedAt`, `UnassignedAt`) | "Who had table 8 at 20:15?" | Turns an audit question into an index seek, even across a year of service |

Plus `UX_Payments_Restaurant_IdempotencyKey`, which is what actually stops a
guest being charged twice when a cashier taps through a bad connection.

---

## Conventions

| Concern | Choice | Reason |
|---|---|---|
| Primary keys | `Guid` v7 (sequential) | Not enumerable from outside; generated client-side so a whole object graph can be built before commit; sequential so the clustered index does not fragment — the defect that makes v4 Guids a bad key |
| Human numbers | Separate columns (`OrderNumber`, `SessionNumber`, `TicketNumber`) | Nobody shouts a Guid across a kitchen |
| Money | `decimal(18,2)` + currency column | Exact in base 10. HTG runs to six figures and binary rounding on repeated additions becomes a real cash-drawer discrepancy |
| Enums | Stored as **names** | `'SERVED'` beats `6` in a support query, and reordering an enum can never silently reinterpret existing rows |
| Timestamps | `datetimeoffset(3)`, UTC | Local times in a database are ambiguous twice a year and unrecoverable afterwards. Conversion happens at display time |
| Concurrency | `rowversion` on Orders, Sessions, Tables, Tickets, Products | Optimistic by default; cost is zero when there is no conflict, which is almost always |
| Deletion | None, on anything historical | `Order`, `Payment`, `AuditLog`, both assignment tables: `DeleteBehavior.Restrict`. Reference data is deactivated instead, or historical rows lose their joins |

### Why `Money` carries its own currency column

A value converter cannot read sibling properties, so `Money` could not be rebuilt
from a currency stored elsewhere on the row. Carrying it alongside each amount is
mildly redundant with `Order.Currency` and buys a value object that is always
valid on materialisation. Worth challenging — see PHASE-2-NOTES.md §5.

---

## Snapshots

`OrderItem` copies, rather than references:

```
ProductNameSnapshot   UnitPriceSnapshot   TaxRateSnapshot   StationCodeSnapshot
```

A product can later change price, be renamed, move station, or be retired. None
of that may rewrite what was sold last Tuesday. Without these columns every
historical revenue figure is fiction the moment a price changes.

The same reasoning applies to `OrderItemModifier` and `PreparationTicketItem`.

---

## Volumetry, one venue, one year

| Table | Rows | Note |
|---|---:|---|
| Orders | ~55 000 | 150/day |
| OrderItems | ~200 000 | 3.5 lines per order |
| PreparationTickets | ~80 000 | 1.5 per order |
| TableSessions | ~20 000 | |
| ServiceAssignmentHistory | ~30 000 | |
| **AuditLogs** | **~500 000** | dominant |

At a hundred venues that is fifty million audit rows a year. **Partition
`AuditLogs` monthly on `CreatedAt` and archive beyond the retention period** —
this is structural, not cosmetic, and should be scheduled before the second year
rather than after it.

`AuditLogs` uses a `bigint` identity key rather than a Guid: it is the highest
volume table, append-only, and written far more often than read, so a narrow
clustered key wins.

---

## Migrations

```bash
dotnet ef migrations add <Name> \
  --project src/STED.RestaurantOS.Infrastructure \
  --startup-project src/STED.RestaurantOS.API

dotnet ef migrations script --idempotent --output deploy.sql
```

Applied by a deployment step, never at application startup: two instances
migrating in parallel corrupt a database.

During a rolling deploy, keep every migration backwards compatible — add before
removing, across two releases. A column dropped in the same release that stops
using it takes down the instances still running the old code.

> **No migration exists yet.** The authoring environment had no .NET SDK, so
> `InitialCreate` still has to be generated and reviewed against §22 of the
> Phase 1 analysis.
