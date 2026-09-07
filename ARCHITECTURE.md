# SchoolFlow — Architecture

> *La gestion scolaire, simplement automatisée.*

SchoolFlow is a multi-tenant SaaS for school administration. This document
describes the system boundaries, the module map, the tenancy model and the
cross-cutting concerns (security, money, background work, observability).

---

## 1. System overview

```
                      ┌──────────────────────────────┐
   Browser  ────────► │  Next.js 15 (App Router)     │
   (SPA-like)         │  React 19 · TanStack Query   │
                      └───────────────┬──────────────┘
                                      │ HTTPS, Bearer token (Sanctum PAT)
                                      ▼
                      ┌──────────────────────────────┐
                      │  Nginx  →  PHP-FPM           │
                      │  Laravel 12 REST API /api/v1 │
                      └───┬───────────┬───────────┬──┘
                          │           │           │
              ┌───────────▼──┐  ┌─────▼─────┐  ┌──▼──────────┐
              │ PostgreSQL 16│  │ Redis 7   │  │ S3 / MinIO  │
              │ system of    │  │ cache,    │  │ private     │
              │ record       │  │ queue,    │  │ documents   │
              └──────────────┘  │ sessions, │  └─────────────┘
                                │ rate limit│
                                └─────┬─────┘
                                      │
                   ┌──────────────────┴──────────────────┐
                   │                                     │
          ┌────────▼────────┐                  ┌─────────▼────────┐
          │ queue:work      │                  │ schedule:work    │
          │ (worker)        │                  │ (scheduler)      │
          │ mail, SMS, PDF, │                  │ reminders,       │
          │ imports, reports│                  │ overdue sweeps   │
          └─────────────────┘                  └──────────────────┘
```

The API is the **only** way into the data. The frontend holds no business
rules: every average, every balance, every permission check is computed
server-side and returned by the API.

---

## 2. Backend layering

The backend follows a pragmatic clean-architecture split. Each domain owns its
entities, its business services and its events; HTTP is a delivery mechanism
that sits on top and knows nothing about persistence details.

```
backend/app/
├── Domain/                  # business model + business rules (framework-light)
│   ├── Identity/            # users, roles, permissions, sessions, 2FA
│   ├── Tenancy/             # tenant context, isolation primitives
│   ├── School/              # schools, settings, academic years
│   ├── Student/             # students, guardians, enrollment, admissions
│   ├── Teacher/             # teachers, assignments, availability
│   ├── Academic/            # levels, classes, subjects, timetable, grades
│   ├── Attendance/          # attendance records and justifications
│   ├── Finance/             # fee types, invoices, payments, receipts
│   ├── Communication/       # message composition and delivery intents
│   ├── Notification/        # templates, channels, logs, preferences
│   ├── Document/            # storage, certificates, verification
│   ├── Reporting/           # aggregations, exports
│   ├── Subscription/        # plans, subscriptions, usage, feature flags
│   ├── Assistant/           # AI tool registry (no raw SQL access)
│   ├── Shared/              # Money, enums, base classes, value objects
│   └── Audit/               # append-only audit trail
│
├── Application/             # use-case orchestration crossing several domains
│   └── ...Actions, DTOs, read-models used by more than one domain
│
├── Infrastructure/          # adapters: gateways, storage, PDF, SMS, AI clients
│   ├── Payments/            # PaymentGatewayInterface implementations
│   ├── Messaging/           # SMS / WhatsApp drivers
│   ├── Pdf/                 # report cards, receipts, certificates
│   └── Export/              # CSV / XLSX writers and readers
│
└── Http/                    # controllers, requests, resources, middleware
    ├── Controllers/Api/V1/
    ├── Requests/
    ├── Resources/
    └── Middleware/
```

**Dependency rule.** `Http → Application → Domain ← Infrastructure`.
`Domain` never imports from `Http`. When a domain needs an external system it
declares an interface (e.g. `PaymentGatewayInterface`, `SmsSender`) that
`Infrastructure` implements and the service container binds.

### Where business rules live

| Rule | Home |
|---|---|
| Weighted subject average | `Domain\Academic\Services\GradeCalculator` |
| Class ranking, tie handling | `Domain\Academic\Services\RankingService` |
| Invoice totals & status transitions | `Domain\Finance\Services\InvoiceService` |
| Payment application & receipt issuance | `Domain\Finance\Services\PaymentService` |
| Timetable conflict detection | `Domain\Academic\Services\TimetableConflictDetector` |
| Plan limit enforcement | `Domain\Subscription\Services\PlanLimitEnforcer` |
| Tenant scoping | `Domain\Tenancy` + `BelongsToTenant` trait |

---

## 3. Multi-tenancy

### Model: shared database, logical isolation by `tenant_id`

Every business table carries a non-null `school_id` (the tenant key). This was
chosen over database-per-tenant because SchoolFlow targets thousands of small
schools: connection-pool cost and migration fan-out would dominate at that
scale, and cross-tenant platform analytics (MRR, churn) stay a single query.

Isolation is enforced at **five** independent layers, so that a mistake at one
layer cannot by itself leak data:

1. **Middleware** — `ResolveTenant` reads the authenticated user's school (or,
   for a platform super-admin, an explicit `X-School-Id` header) and binds a
   `TenantContext` singleton for the request.
2. **Global Eloquent scope** — the `BelongsToTenant` trait adds a
   `where school_id = ?` scope to every query *and* fills `school_id` on
   create. Models cannot be read or written outside the active tenant without
   an explicit, audited `withoutTenantScope()` call.
3. **Policies** — every policy re-checks `$model->school_id === tenant id`
   before checking the permission. Belt and braces: a leaked ID still 403s.
4. **Route-model binding** — bindings resolve through the scoped query, so an
   IDOR attempt on another tenant's UUID returns 404, not 403 (no existence
   oracle).
5. **Database constraints** — composite foreign keys `(school_id, id)` on the
   relationships that matter, so a row can never point at a parent belonging
   to another school even if application code is wrong.

`Domain\Tenancy\TenantContext` is the single source of truth. Queue jobs
serialize the tenant id and re-establish the context on the worker, because a
worker has no HTTP request to read it from.

### Platform vs tenant scope

`Platform Super Admin` is the only role that exists outside a tenant. Its
requests either carry no tenant (platform endpoints under `/api/v1/platform/*`)
or explicitly impersonate one, and every impersonated action is written to the
audit log with `acting_as_platform_admin = true`.

---

## 4. Authorization (RBAC)

```
User ──< UserRole >── Role ──< RolePermission >── Permission
                        │
                        └── permissions are also grantable directly to a user
                            (user_permissions: grant OR revoke)
```

* **Roles** are templates (`platform_super_admin`, `school_owner`,
  `school_admin`, `principal`, `teacher`, `accountant`, `parent`, `student`).
* **Permissions** are fine-grained verbs (`students.create`, `grades.lock`,
  `payments.refund`, …) and are the only thing policies check.
* A per-user override table allows granting or revoking a single permission
  without inventing a new role — the "manage permissions individually"
  requirement.

Effective permissions are computed once per request and cached in Redis, keyed
by `user_id` + a `permissions_version` counter bumped on any role change, so a
revocation takes effect immediately rather than after a TTL.

Teachers get an extra ownership layer: `grades.create` is necessary but not
sufficient — `GradePolicy` also verifies the teacher is assigned to that
(class, subject) pair for the active academic year.

---

## 5. Money

Financial amounts are **never** floats. Every monetary column is a
`BIGINT` holding the amount in *minor units* (centimes/cents), paired with a
`CHAR(3)` ISO-4217 currency column. The `Domain\Shared\Money` value object
wraps them, refuses cross-currency arithmetic, and exposes explicit rounding
for proportional allocation (discounts spread over invoice lines).

Supported out of the box: **HTG** and **USD**. Adding a currency is a config
entry, not a code change.

---

## 6. Payments

The finance domain never talks to a payment provider directly:

```
PaymentController
   → PaymentService (domain)
       → PaymentGatewayInterface        ← the only contract the domain knows
            ├── CashGateway             (recorded manually by an accountant)
            ├── BankTransferGateway     (manual, with reference + proof upload)
            ├── MonCashGateway          (Digicel Haiti)
            ├── NatCashGateway          (Natcom Haiti)
            └── StripeGateway
```

`PaymentGatewayManager` resolves a provider by key and reports it as
*unavailable* when credentials are missing, rather than pretending it works.

**A payment is only ever confirmed server-side.** The browser redirect after a
checkout marks nothing; confirmation comes from a signed webhook that is:

* **authenticated** — HMAC signature verified against the provider secret;
* **idempotent** — `webhook_events` has a unique key on
  `(provider, external_event_id)` and the handler runs inside a transaction
  with a row lock on the invoice;
* **logged** — raw payload retained for reconciliation and dispute handling;
* **verified** — for providers that offer it, the gateway re-queries the
  transaction before applying it.

---

## 7. Background work

| Queue | Contents | Why separate |
|---|---|---|
| `high` | payment webhooks, receipt issuance | must never queue behind a bulk mail run |
| `default` | domain events, cascading updates | |
| `notifications` | e-mail / SMS / WhatsApp fan-out | slow, third-party bound |
| `documents` | report cards, receipts, certificates (PDF) | CPU bound |
| `reports` | large exports (CSV/XLSX), imports | long running |
| `low` | statistics recomputation, cleanup | preemptible |

Every job is idempotent: it either carries a natural key it checks before
acting (a receipt number, a notification log row) or is a pure recomputation.

Scheduled commands (`routes/console.php`):

```
schoolflow:send-payment-reminders      hourly   J-7 / J-3 / J-1 / J+1 / J+7
schoolflow:check-overdue-invoices      daily    flips ISSUED → OVERDUE
schoolflow:generate-recurring-invoices daily    term/monthly fee schedules
schoolflow:process-notifications       every 5m retries transient failures
schoolflow:calculate-statistics        hourly   dashboard read-models
schoolflow:check-subscriptions         daily    trial end, expiry, suspension
schoolflow:cleanup-expired-files       daily    orphan uploads, expired exports
```

---

## 8. Documents

Files never live in the web root. Uploads go to S3/MinIO under
`schools/{school_id}/{collection}/{uuid}.{ext}`; download is always a
short-lived signed URL minted after a policy check, and every mint is written
to the audit log as `DOCUMENT_DOWNLOAD`.

Certificates (enrolment certificate, transcript, completion certificate) carry
a random, non-sequential `verification_code`. The public route
`GET /api/v1/verify/{code}` — the only unauthenticated read endpoint — returns
a minimal attestation (document type, school, holder initials, issue date,
validity) without exposing personal data.

---

## 9. API conventions

* Base path `/api/v1`; the version is in the URL so breaking changes can run
  side by side.
* Bearer tokens (Laravel Sanctum personal access tokens); abilities on the
  token narrow, never widen, the user's permissions.
* Uniform envelope:

```jsonc
// success
{ "success": true, "data": {...}, "message": "..." , "meta": {...} }
// failure
{ "success": false, "message": "Validation failed", "errors": {"field": ["..."]} }
```

* Collections are always paginated (`?page`, `?per_page`, capped at 100) and
  support `?search`, `?sort=-created_at`, and documented per-resource filters.
* Correct status codes: `201` on create, `204` on delete, `409` on a domain
  conflict (e.g. timetable clash), `422` on validation, `402` when a plan limit
  blocks the action, `429` on throttling.
* Every response carries `X-Request-Id` for log correlation.

Full reference: [`docs/API.md`](docs/API.md) and the OpenAPI document at
[`docs/openapi.yaml`](docs/openapi.yaml).

---

## 10. Frontend

```
frontend/src/
├── app/                  # Next.js App Router: route groups per audience
│   ├── (public)/         # landing, admissions form, /verify/[code]
│   ├── (auth)/           # login, register, password reset
│   └── (dashboard)/      # authenticated shell, role-aware navigation
├── components/           # design-system primitives (shadcn/ui derived)
├── features/             # one folder per module: api hooks + screens
├── lib/                  # api client, i18n, auth, formatting
├── hooks/  services/  types/  utils/
```

* **Server state** is owned by TanStack Query. There is no duplicated client
  cache and no hardcoded fixture data anywhere in the tree.
* **Forms** are React Hook Form + Zod; the same Zod shapes type the API
  payloads, and server-side validation errors are mapped back onto fields.
* **i18n** — `fr` (default), `en`, `ht`. No user-facing string is inlined in a
  component; everything resolves through the dictionary.
* **Accessibility** — WCAG 2.2 AA is the target: keyboard-navigable dialogs and
  menus, visible focus rings, labelled controls, `aria-live` error summaries,
  and contrast-checked tokens in both themes.

---

## 11. Observability

* `GET /health` — liveness, always cheap, no dependencies touched.
* `GET /ready` — readiness: database, Redis, object storage, queue depth.
* Structured JSON logs with request id, user id, school id.
* `audit_logs` is the tamper-evident record of who did what, in which tenant,
  from which IP — append-only, never exposed to non-privileged roles.

---

## 12. Recorded decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | Shared DB + `school_id` isolation | thousands of small tenants; migration and pooling cost of DB-per-tenant is prohibitive |
| 2 | UUIDv7 primary keys | non-guessable in URLs (IDOR resistance) while staying index-friendly, unlike UUIDv4 |
| 3 | Integer minor units for money | float arithmetic is unacceptable in billing |
| 4 | Server-authoritative payments | a client callback is not evidence of payment |
| 5 | Permissions, not roles, in policies | roles change per school; verbs are stable |
| 6 | Report cards rendered asynchronously | a 300-student PDF run must not hold an HTTP worker |
| 7 | AI restricted to a tool registry | an LLM must never reach the database directly |
| 8 | 404 (not 403) on cross-tenant IDs | avoids an existence oracle across tenants |
