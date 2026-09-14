# API

REST over HTTPS, JSON, camelCase, UTC timestamps. Base path `/api/v1`.
Swagger is served in development only, at `/swagger`.

---

## Envelope

Every response has the same shape, so client code has one place that decides
whether a call worked.

```json
{ "success": true, "data": { }, "traceId": "0HN7..." }
```

```json
{
  "success": false,
  "message": "Another waiter has just taken this table.",
  "code": "TABLE_ALREADY_ASSIGNED",
  "traceId": "0HN7..."
}
```

Switch on `code`. `message` is for humans and may be reworded at any time.

`traceId` matches the `X-Correlation-Id` response header, the Serilog entries and
the audit rows. One incident, one query.

---

## Two kinds of bearer token

**Staff** tokens carry roles and permissions. **Guest** tokens, issued by scanning
a table, carry only the session they belong to and use a separate audience — they
can never satisfy a staff policy.

---

## Idempotency

Mandatory on the endpoints where a repeat costs real money or real food:

```
POST /public/orders
POST /orders/sessions/{id}
POST /orders/{id}/confirm
POST /payments
POST /payments/{id}/refund
```

```http
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

Generate one **per user action** and reuse it on every retry of that action.
Same key, same body → the original response, nothing created. Same key,
different body → `409 IDEMPOTENCY_KEY_REUSED`, because that is a client bug or
an attack, not a retry. Missing → `400`.

---

## Endpoints

### Auth — `/api/v1/auth`

| | | |
|---|---|---|
| `POST` | `/login` | Email + password, or employee code + PIN + restaurant slug |
| `POST` | `/refresh` | Rotates. Replaying a rotated token kills every session |
| `POST` | `/logout` | |
| `GET` | `/me` | Profile, roles, permissions |
| `POST` | `/change-password` | Ends every other session |

### Guest — `/api/v1/public`

| | | Auth |
|---|---|---|
| `GET` | `/qr/{token}` | anonymous — the only one |
| `GET` | `/menu` | guest |
| `POST` | `/cart/price` | guest — **the only total that counts** |
| `POST` | `/orders` | guest + idempotency key |
| `GET` | `/orders/{id}` | guest, own session only |
| `GET` | `/session` | guest |
| `POST` | `/session/call-waiter` · `/session/request-bill` | guest |

### Floor — `/api/v1`

| | | Permission |
|---|---|---|
| `GET` | `/tables` | `Tables.View` |
| `POST` | `/tables/{id}/take` | `Tables.Assign` |
| `POST` | `/tables/{id}/transfer` | `Tables.Transfer` |
| `POST` | `/tables/{id}/release` | `Tables.Assign` |
| `POST` | `/tables/{id}/qr/regenerate` | `Restaurant.Manage` |
| `POST` | `/table-sessions/{id}/close` | `Tables.Assign` |
| `GET` | `/waiter/me/tables` | `Tables.View` |
| `GET` | `/service-assignments/at?tableId=&at=` | `Tables.View` |
| `GET` | `/service-assignments/history` | `Tables.View` |

`/service-assignments/at` is the audit question: *who was responsible for this
table at this instant?*

### Orders, menu, stations

| | | Permission |
|---|---|---|
| `GET` | `/orders` | `Orders.View` |
| `POST` | `/orders/sessions/{sessionId}` | `Orders.Create` + key |
| `POST` | `/orders/{id}/serve` | `Orders.Serve` |
| `POST` | `/orders/{id}/cancel` | `Orders.Cancel` |
| `POST` | `/orders/{id}/discount` | `Payments.Discount` |
| `GET` | `/menu` · `/menu/products` | `Menu.View` |
| `PATCH` | `/menu/products/{id}/availability` | `Menu.Manage` |
| `GET` | `/kitchen/snapshot` · `/bar/snapshot` | `Kitchen.View` / `Bar.View` |
| `POST` | `/kitchen/tickets/{id}/{accept\|start\|ready}` | `Kitchen.Manage` |

**`/snapshot` is the source of truth for a display.** Real-time push is an
accelerator; clients call this on reconnect and every fifteen seconds regardless.

### Billing and reports

| | | Permission |
|---|---|---|
| `GET` | `/cashier/pending-sessions` | `Payments.View` |
| `GET` | `/cashier/sessions/{id}/bill` | `Payments.View` |
| `POST` | `/payments` | `Payments.Create` + key |
| `GET` | `/payments/{id}/receipt` | `Payments.View` |
| `GET` | `/reports/{sales\|waiters\|tables\|products\|stations\|cancellations\|payments}` | `Reports.View` |
| `GET` | `/dashboard/admin` | `Reports.View` |

---

## Error codes

| Code | HTTP | |
|---|:-:|---|
| `VALIDATION_ERROR` | 400 | |
| `IDEMPOTENCY_KEY_REQUIRED` | 400 | |
| `UNAUTHORIZED` | 401 | |
| `FORBIDDEN` | 403 | |
| `NOT_FOUND` · `ORDER_NOT_FOUND` · `TABLE_NOT_FOUND` | 404 | |
| `QR_CODE_INVALID` | 404 | Unknown, expired and retired are one answer |
| `TABLE_ALREADY_ASSIGNED` | 409 | Message names who has it |
| `SESSION_CLOSED` · `SESSION_HAS_UNPAID_ORDERS` | 409 | |
| `PRODUCT_UNAVAILABLE` | 409 | Sold out between choosing and sending |
| `INVALID_ORDER_STATUS_TRANSITION` | 409 | |
| `CONCURRENCY_CONFLICT` | 409 | Reload and retry |
| `PAYMENT_EXCEEDS_TOTAL` | 409 | |
| `IDEMPOTENCY_KEY_REUSED` | 409 | Same key, different body |
| `RATE_LIMIT_EXCEEDED` | 429 | |
| `INTERNAL_ERROR` | 500 | Generic in production, always |

A cross-tenant request answers `404`, never `403`.

---

## Paging

```
?page=1&pageSize=20&sortBy=createdAt&sortDirection=desc&search=&from=&to=
```

`pageSize` is clamped server-side to 100. Counting happens in SQL, before paging.

---

## Real time — `/hubs/restaurant`

Token in the `access_token` query parameter, because a WebSocket handshake
carries no `Authorization` header.

`order.created` · `order.status_changed` · `order.ready` · `order.served` ·
`order.cancelled` · `ticket.created` · `ticket.status_changed` ·
`payment.completed` · `table.assigned` · `table.transferred` · `table.released` ·
`session.closed` · `bill.requested`

Groups are assigned server-side from token claims. There is no client method to
join one.

**Treat every payload as a nudge, not as data.** Invalidate and re-read. An event
that is lost, duplicated or out of order must not be able to change what a screen
shows.
