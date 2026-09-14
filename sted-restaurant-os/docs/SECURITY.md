# Security

---

## Threat model, in one line each

| Threat | What stops it |
|---|---|
| One restaurant reads another's data | Three independent tenancy layers, below |
| A guest reads the next table's bill | Guest token carries its session id; every guest route takes the session from the token, never the URL |
| Someone orders for a table they are not at | QR tokens are 256 random bits, stored hashed, compared in constant time |
| A stolen refresh token | Rotation on every use; replaying a rotated token revokes the whole chain |
| A cashier charging twice | Idempotency key + unique index + total-not-exceeded check |
| Brute-forcing a four-digit PIN | Same lockout as passwords: 5 attempts, 15 minutes |
| A waiter transferring someone else's table | The aggregate refuses unless you hold it or are a manager |
| A kitchen screen taking payments | Granular permissions; `Kitchen` has no `Payments.*` at all |

---

## Tenancy, three layers

**1 — Resolution.** `HttpTenantContext` reads `restaurant_id` from the signed
token. There is no code path that reads a header, a route value, a query string
or a body. A client asking to act on another restaurant is simply not heard.

**2 — Reads.** An EF global query filter on every `ITenantEntity`. Developers
never write the `WHERE` clause, so they cannot forget it. No authenticated
restaurant resolves to `Guid.Empty`, which matches no row — the default is to
show nothing, not everything.

**3 — Writes.** `TenantGuardInterceptor` stamps the caller's restaurant onto
inserts whatever was supplied, and refuses updates or deletes that cross a
boundary — logging them at critical and raising `TenantViolationException`.

A cross-tenant request answers **404, never 403**. Confirming that another
venue's record exists is itself a disclosure.

---

## Tokens

| Token | Lifetime | Carries | Notes |
|---|---|---|---|
| Access (staff) | 15 min | roles, permissions, restaurant, staff profile | Short: a stolen token is useful for minutes |
| Refresh | 14 days | nothing — opaque 256 bits | **Stored as SHA-256.** A database dump yields nothing presentable |
| Guest | 4 h | session id, table id, restaurant id | Separate audience, **no permission claim at all**, so it can never satisfy a staff policy |
| QR token | until rotated | nothing | 256 random bits, stored hashed, base64url |

### Refresh rotation and reuse detection

Every refresh issues a new token and revokes the old one. **Presenting an already
rotated token revokes every session for that account** and logs a security event.

The reasoning: a legitimate client never replays a token it has already
exchanged. That pattern means a copy is in circulation, and the safe response is
to end every session and make the user sign in again.

Client-side, refreshes are deduplicated: four queries hitting 401 together must
not fire four refreshes, or the burst would look exactly like theft.

---

## Authorisation

Three layers, each doing something the others cannot:

1. **Policy** — `[Authorize(Policy = "Orders.Cancel")]`. Answers *may this person
   do this kind of thing at all*.
2. **Resource** — may they do it to *this* table? A policy cannot answer that.
3. **Domain** — the aggregate refuses the illegal operation even when the caller
   was authorised. Belt and braces.

Permissions are granular; roles are a convenient way to hand out sets of them.
Per-user overrides exist, and **revocations are applied last**: taking a
permission away from one person must not be undone by a role that also grants it.

The frontend's permission list decides what to *render*. The server checks again
on every call, because anything decided in a browser is a suggestion.

---

## QR codes

`/order/t/12` would let anyone order for any table from the car park. So:

- 32 random bytes, base64url;
- only the SHA-256 hash is stored, exactly like a password;
- a short non-secret prefix (`TokenLookupKey`) turns resolution into an index
  seek — knowing it reveals nothing, because the full hash is still verified;
- comparison uses `CryptographicOperations.FixedTimeEquals`. Comparing hashes
  with `==` leaks how many leading bytes matched, which is enough to reconstruct
  a token given patience;
- unknown, expired and retired tokens all return one identical 404. Telling them
  apart would let anyone map the venue's tables.

---

## Rate limiting

| Endpoint | Limit | Key | Why that key |
|---|---|---|---|
| `POST /auth/login` | 5/min | IP + email | One attacker must not be able to lock out a whole venue |
| `GET /public/qr/{token}` | 20/min | IP | A table of six all scan at once |
| `POST /public/orders` | 5/min | **session** | A whole restaurant shares one NAT address |
| Staff API | 300/min | user | A KDS polls every fifteen seconds all day |

---

## OWASP Top 10

| | Covered by |
|---|---|
| A01 Broken Access Control | Three tenancy layers, policies, resource checks, planned isolation tests in CI |
| A02 Cryptographic Failures | HTTPS/HSTS, refresh and QR tokens hashed, PBKDF2 passwords, secrets from the environment |
| A03 Injection | EF Core parameterised throughout; no concatenated SQL anywhere |
| A04 Insecure Design | Invariants modelled in the domain and backed by database constraints |
| A05 Misconfiguration | CORS allow-list, security headers at nginx, Swagger disabled outside development, no stack traces in production |
| A06 Vulnerable Components | `dotnet list package --vulnerable` and `npm audit` in CI, Dependabot weekly |
| A07 Auth Failures | Lockout, short access tokens, rotation with reuse detection, revocation on password change |
| A08 Data Integrity | Idempotency, rowversion, transactions, append-only audit |
| A09 Logging Failures | Structured Serilog, correlation id through logs/audit/response, security events logged at critical |
| A10 SSRF | No user-controlled outbound requests |

---

## Secrets

Never in `appsettings.json`, never in the repository. `.env.example` documents
the variables and holds no values.

`JwtTokenService` refuses to construct with a signing key shorter than 32
characters, so a misconfigured deployment fails at startup rather than running
with something weak.

```bash
openssl rand -base64 48
```

---

## What is not done yet

- Integration tests proving tenant isolation endpoint by endpoint (Phase 15).
- Concurrency tests: ten waiters taking one table, five identical payments.
- A security review against a running instance — everything above is design and
  code reading, not a penetration test.
