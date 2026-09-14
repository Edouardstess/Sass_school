# Architecture

Companion to the full analysis in
[`PHASE-1-ANALYSE.md`](../../docs/sted-restaurant-os/PHASE-1-ANALYSE.md). This is
the short version: what the code actually does and why it is shaped this way.

---

## 1. Shape

A modular monolith with Clean Architecture in cross-section.

```
API            controllers · SignalR hub · middlewares · filters
  ↓
Application    use-case contracts · DTOs · permissions · Result
  ↓
Domain         aggregates · value objects · domain events      ← depends on nothing
  ↑
Infrastructure EF Core · Identity · services · realtime · providers
```

Dependencies point inward. `Domain` references no framework at all, and a unit
test fails the build if that ever changes.

**Why not microservices.** Creating an order writes the order, its lines, its
status history and one preparation ticket per station. Those must commit or fail
together. Splitting them across services would replace one transaction with a
distributed saga, buying nothing at this scale and losing the property the whole
audit trail rests on.

---

## 2. The two decisions everything else follows from

### `ServiceAssignment` lives inside `TableSession`

"A table has at most one waiter at a time" has to be true *immediately*. If
assignments were their own aggregate, two concurrent requests could each create
an active one and no aggregate would notice.

Putting them inside the session means `TransferTo(...)` writes the history row in
the same method body. **There is no code path that changes the responsible waiter
without recording it.** Traceability stops being a convention developers have to
remember and becomes a property of the type.

The database says the same thing independently:

```sql
UX_ServiceAssignments_ActiveBySession  UNIQUE (TableSessionId) WHERE Status = 'Active'
UX_TableSessions_OpenByTable           UNIQUE (TableId) WHERE Status IN ('Open','Active')
```

Application code cannot win a race. These can. A lost race surfaces as a unique
violation the API turns into `409 TABLE_ALREADY_ASSIGNED`, naming who actually
got the table — never as two waiters both believing it is theirs.

### `Order.WaiterId` is frozen; the assignment chain stays live

Two things are true at once and must not be conflated:

- **Who is responsible for this table right now** — the session's active
  assignment, which changes when a table is handed over.
- **Who was responsible for this order** — frozen on the order at confirmation,
  for ever.

Jean opens table 12 at 19:00, an order lands at 19:15, he hands the table to
Marie at 19:45, another order lands at 20:00. The first order stays Jean's. The
second is Marie's. Neither is rewritten, and the revenue report splits correctly
between them because it reads the frozen column.

---

## 3. Aggregates

| Aggregate | Owns | Invariant it exists to protect |
|---|---|---|
| `Restaurant` | `RestaurantSettings` | One settings row per venue (the FK *is* the PK) |
| `RestaurantTable` | `TableQrCode` | At most one active QR code |
| **`TableSession`** | `ServiceAssignment`, `ServiceAssignmentHistory` | **One active waiter; every hand-over recorded** |
| `Product` | `ProductModifier`, `ModifierOption` | `min ≤ max`, station mandatory, price ≥ 0 |
| **`Order`** | `OrderItem`, `OrderItemModifier`, `OrderStatusHistory` | **Totals derive from lines; legal transitions only; waiter frozen** |
| `PreparationTicket` | `PreparationTicketItem` | Forward-only station workflow |
| `Payment` | — | Positive; sum never exceeds the order total |
| `AuditLog` | — | Append-only: the type has no mutator at all |

### Why tickets are a separate aggregate

A cook tapping "accept" must not take a lock on the whole order and collide with
a guest adding a line. Tickets therefore live outside `Order` and reconcile
through domain events: all tickets ready → `READY`, some ready →
`PARTIALLY_READY`.

That is eventual consistency, measured in milliseconds, bought deliberately. It
also produces the behaviour the floor actually wants: drinks leave the bar while
the main course is still cooking.

---

## 4. The order transaction

The order of operations is not negotiable.

```
1  validate the session is open
2  load every product in ONE query
3  re-check availability inside the transaction
4  validate modifier selections against the product's rules
5  snapshot name, price, tax rate, station
6  compute line by line, rounding once per line
7  resolve the waiter from the session's active assignment (may be null)
8  generate the order number
9  persist order + lines + status history
10 group lines by station → one preparation ticket each
11 write the audit entry
12 record the idempotency key
13 COMMIT
14 only now: publish real-time events
```

Step 14 comes last on purpose. Announcing an order before the commit puts a
ticket on a kitchen screen for an order a rollback then erased.

Step 12 is inside the transaction for the same class of reason: recording the
idempotency key afterwards leaves a window where a crash produces a real order
with no trace of it, and the client's retry creates a second one — exactly the
bug the key exists to prevent.

---

## 5. Multi-tenancy

Single database, shared schema, `RestaurantId` discriminator. Isolation is
therefore enforced by code, which means it is defended three times over:

1. **Resolution** — `HttpTenantContext` reads the restaurant from the signed
   token and from nothing else. No header, route value, query string or body is
   consulted, anywhere.
2. **Reads** — an EF global query filter on every entity carrying a
   `RestaurantId`. A developer cannot forget the `WHERE` clause because they
   never write it. No authenticated restaurant means `Guid.Empty`, which matches
   no row: the safe default is to show nothing.
3. **Writes** — `TenantGuardInterceptor` stamps the caller's restaurant onto new
   rows whatever they asked for, and refuses cross-tenant updates with a critical
   security log rather than executing them.

A fourth net is planned: integration tests that hit every endpoint as restaurant
B and assert it sees nothing of restaurant A.

---

## 6. Real time

One hub, many groups: `restaurant:{id}`, `station:{id}`, `table:{id}`,
`user:{id}`, `role:{restaurant}:{role}`.

**Group membership is decided server-side** in `OnConnectedAsync`, from the
token's claims. There is no client-callable "join this restaurant" method —
if there were, tenant isolation would be one line of browser JavaScript away from
being bypassed.

Payloads are signals, not data. Clients invalidate a query and re-read; they
never write a payload into a cache. So an event that is lost, duplicated or
arrives out of order cannot leave a screen showing something the server never
said.

Every live screen also polls — fifteen seconds on the displays. A kitchen screen
that silently stops updating does not look broken. It looks like a quiet evening,
and the food stops going out.

---

## 7. Where the important code lives

```
Domain/Floor/TableSession.cs                    the traceability guarantee
Domain/Ordering/Order.cs                        every amount, and the frozen waiter
Domain/Ordering/OrderStatusTransitions.cs       the transition matrix, as data
Infrastructure/Services/OrderService.cs         the transaction above
Infrastructure/Persistence/Configurations/
  FloorConfigurations.cs                        the filtered unique indexes
Infrastructure/Persistence/Interceptors/
  TenantGuardInterceptor.cs                     tenancy on the write path
API/Tenancy/HttpTenantContext.cs                tenancy at the door
```
