# Deployment

---

## Environments

| | Database | Secrets | Swagger | Logs |
|---|---|---|---|---|
| Development | local container | .NET user secrets | on | console, debug |
| Staging | managed instance | orchestrator env vars | off | structured, information |
| Production | managed instance, backed up | vault / orchestrator | **off** | structured, information |

---

## Starting the stack

```bash
cp .env.example .env
# SQL_PASSWORD and JWT__SIGNINGKEY have no defaults, deliberately
docker compose up --build
```

| Service | Port | |
|---|:-:|---|
| nginx | 80 / 443 | entry point |
| api | 8080 | behind nginx |
| frontend | 5173 → 80 | static assets |
| sqlserver | 1433 | volume `mssql-data` |

The API waits for SQL Server's healthcheck. Starting against a database that is
still booting produces a confusing failure rather than a clear one.

---

## Secrets

Everything sensitive comes from the environment. Nothing sensitive is in the
repository, and `.env` is ignored.

```bash
openssl rand -base64 48   # JWT__SIGNINGKEY — generate, do not invent
```

`JwtTokenService` refuses a key under 32 characters, so a misconfigured
deployment fails at startup instead of running with something weak.

---

## Migrations

**Never at application startup.** Two instances migrating in parallel corrupt a
database.

```bash
dotnet ef migrations script --idempotent \
  --project src/STED.RestaurantOS.Infrastructure \
  --startup-project src/STED.RestaurantOS.API \
  --output deploy.sql

sqlcmd -S "$SQL_SERVER" -d StedRestaurantOs -i deploy.sql
```

Then deploy the application.

During a rolling deploy every migration must be backwards compatible: add
before removing, across two releases. A column dropped in the same release that
stops using it takes down the instances still running the old code.

Permissions and built-in roles *are* seeded at startup. That is idempotent, adds
only, and is how a permission added in code reaches an existing database.

---

## Health

| Endpoint | Checks | Used by |
|---|---|---|
| `/health/live` | the process answers | container restart |
| `/health/ready` | SQL Server reachable | load balancer, rolling deploy |

Liveness deliberately does **not** check the database. If it did, a brief
database blip would make the orchestrator kill healthy instances and turn a
thirty-second outage into a ten-minute one.

---

## Scaling

One API instance handles a venue comfortably. Beyond that:

1. **SignalR needs a backplane.** Add Redis behind `IRealtimeNotifier`; no use
   case changes.
2. Sticky sessions are not required — the API holds no session state.
3. Read-heavy reporting can move to a replica before it ever needs CQRS.

The first thing to watch is not CPU. It is `AuditLogs` growth: half a million
rows per venue per year. Partition monthly and archive past the retention period.

---

## Backups

| | |
|---|---|
| Full | nightly, retained 30 days |
| Differential | every 6 hours |
| Log | every 15 minutes |
| Restore drill | **quarterly** — an untested backup is a hope, not a backup |

---

## Monitoring

Watch these, in this order:

1. `TENANT_VIOLATION_ATTEMPT` in the audit log — **any occurrence is an incident**
2. `REFRESH_TOKEN_REUSE_DETECTED` — a token is in circulation
3. 5xx rate
4. p95 latency on `POST /public/orders` and `GET /kitchen/snapshot`
5. Late-ticket count — an operational signal before it is a technical one
6. SQL deadlocks and `CONCURRENCY_CONFLICT` responses
7. `AuditLogs` row count

Every log line carries `CorrelationId`, `UserId` and `RestaurantId`, so a
support ticket resolves to one query.

---

## Before the first production deployment

- [ ] Backend compiles and tests pass (never yet done — see the README)
- [ ] `InitialCreate` generated and reviewed against the Phase 1 MLD
- [ ] Integration tests proving tenant isolation, endpoint by endpoint
- [ ] Concurrency tests: ten waiters on one table, five identical payments
- [ ] TLS certificates installed; HSTS confirmed
- [ ] CORS allow-list set to the real front-end origin
- [ ] Signing key generated and stored in the vault
- [ ] Backups running and one restore actually rehearsed
- [ ] Warnings-as-errors switched back on in `Directory.Build.props`
