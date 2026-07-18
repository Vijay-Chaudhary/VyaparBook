# Superadmin / Platform Console — Design Spec

**Date:** 2026-07-18
**Status:** Approved for planning
**Parent doc:** VyaparBook PRD v2.0 (Multi-Tenant SaaS), §12 (Superadmin), §11 (platform API), §13 (auditability)

## 1. Purpose & scope

A separate, platform-admin-gated surface for the operator to run the business: see every tenant, its subscription and usage, suspend/reactivate a tenant, and verify/reject the manual/UPI subscription payments the Billing slice records. This is the **first surface that deliberately crosses tenant boundaries** — it is not part of any tenant's data and is guarded by the `is_platform_admin` flag, not a business membership (PRD §12).

It closes the loop left open by Billing: Billing lets an owner *record* a payment (`pending`); Superadmin *verifies* it and activates the plan via the `SubscriptionService::activateFromPayment()` seam.

**In scope:**
- Platform-admin auth: a `require.platform_admin` middleware over `auth:api` (checks `is_platform_admin` live from the DB); platform routes carry no tenant context.
- A least-privilege `BYPASSRLS` DB connection (`pgsql_platform`) for cross-tenant **reads**; tenant-scoped **writes** via `TenantContext::switchTo`.
- `GET /admin/tenants`, `GET /admin/tenants/{id}` — list/detail with subscription + usage.
- `POST /admin/tenants/{id}/suspend`, `/reactivate` — flip `subscription.status`.
- `POST /admin/payments/{id}/verify`, `/reject` — activate or reject a recorded payment.
- `GET /admin/metrics` — platform-wide counts.
- `platform_audit_logs` — append-only trail of every platform mutation.
- The inverse-isolation test suite: a non-platform-admin is hard-403'd from every `/admin` route; a platform admin's reads genuinely span tenants.

**Out of scope (deferred):**
- **Impersonate-for-support** (PRD §12) — issuing a scoped tenant token as an admin; sensitive, needs its own audited auth path. A later slice (the audit table built here is the foundation).
- **Per-tenant data export / backup** (PRD §12/§13, DPDP) — an RLS-scoped logical dump per tenant; its own compliance-focused slice.
- **Platform web UI** — this is the API only; the console frontend is a frontend slice.
- **Automated dunning** that *sets* `read_only` (Phase 2) — here suspension is a manual admin action.
- **CREATE-ing platform admins via API** — the `is_platform_admin` flag is set out-of-band (seed/tinker) in v1; a "manage platform admins" screen is later.

## 2. Stack decisions for this slice

- **Backend:** Laravel (PHP 8.3), following the existing slices.
- **A second DB connection, `pgsql_platform`:** a dedicated Postgres role with `BYPASSRLS` and least privilege (SELECT on tenant tables; the narrow UPDATE on `subscriptions`/`subscription_payments` is *not* needed here because writes go through the normal RLS connection). The role is created by a migration running on the superuser `pgsql_migrate` connection. This is the standard "admin plane over RLS" pattern and mirrors how `pgsql_migrate` already bypasses RLS in tests — but as a purpose-built least-privilege runtime role, not the superuser.
- **Testing:** Pest; a platform-admin JWT helper; the `pgsql_platform` connection exercised in tests.

## 3. Auth & the platform guard

- A platform admin is a `User` with `is_platform_admin = true` (column already exists from the tenancy-core migration; unused until now). They authenticate through the **existing** auth endpoints and receive a normal JWT.
- **`require.platform_admin` middleware** — runs after `auth:api`. Resolves the authenticated user id, loads `User::find($id)`, and aborts `403` unless `is_platform_admin`. Checked **live from the DB** (not a JWT claim) so revoking the flag takes effect on the next request. It sets no tenant context.
- Platform routes are grouped under `auth:api` + `require.platform_admin` **only** — no `tenant.context`, no `require.tenant`. A user who is both a platform admin and a business owner reaches `/admin/*` by the flag alone; any `tid` on their token is ignored there.
- **The inverse guarantee:** every `/admin/*` route must 403 for a non-flag user regardless of their tenant role (owner included) and for an unauthenticated request. This is the security core of the slice and is tested exhaustively.

## 4. Cross-tenant data access (hybrid)

Tenant tables use `FORCE` RLS, so a query with no `app.current_tenant` returns zero rows — a platform admin cannot read across tenants the ordinary way.

- **Reads → `pgsql_platform` (BYPASSRLS).** Platform read paths query on the `pgsql_platform` connection, which bypasses RLS, so a single aggregate query spans all tenants (e.g. per-tenant customer counts). Read models use explicit `->on('pgsql_platform')` and, for Eloquent, `->withoutGlobalScopes()` so the `BelongsToTenant` app scope does not re-narrow them. `businesses`/`memberships` are queried the same way for consistency.
- **Writes → normal connection, `switchTo` the target.** Suspend/reactivate/verify/reject each `TenantContext::switchTo($targetBusinessId)` and then perform **one** scoped update. RLS `WITH CHECK` pins the mutation to exactly that business, so a platform bug cannot write across tenants. Each such handler resolves the target business id first (from the tenant row or the payment's `business_id`, read via `pgsql_platform`), then switches and writes.

```php
// config/database.php — 'pgsql_platform' mirrors 'pgsql' but with the BYPASSRLS role's
// credentials (DB_PLATFORM_USERNAME / DB_PLATFORM_PASSWORD in .env), same host/db/port.

// migration (on pgsql_migrate, the superuser): create the role once, idempotently
//   DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='vyapar_platform_ro')
//     THEN CREATE ROLE vyapar_platform_ro LOGIN PASSWORD '...' BYPASSRLS; END IF; END $$;
//   GRANT CONNECT ON DATABASE ... TO vyapar_platform_ro;
//   GRANT USAGE ON SCHEMA public TO vyapar_platform_ro;
//   GRANT SELECT ON ALL TABLES IN SCHEMA public TO vyapar_platform_ro;
//   ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO vyapar_platform_ro;
```

The role is read-only (SELECT + BYPASSRLS). It never writes — writes are the normal connection's job. Least privilege: a leaked platform-read credential cannot mutate anything.

## 5. Data model

One new table, **platform-owned** (not a tenant table): no `business_id`-based RLS, no `BelongsToTenant`. It records actions *about* tenants, taken by the platform, so it is not itself tenant-scoped.

```php
// platform_audit_logs — append-only trail of platform mutations
$table->uuid('id')->primary();
$table->foreignId('admin_user_id')->constrained('users');   // who acted (bigint users.id)
$table->string('action', 40);          // suspend | reactivate | verify_payment | reject_payment
$table->foreignUuid('target_business_id')->nullable()->constrained('businesses');
$table->jsonb('metadata')->nullable(); // e.g. {payment_id, from_status, to_status, amount}
$table->timestamp('created_at')->nullable();
$table->index(['target_business_id', 'created_at']);
```

Append-only: rows are inserted, never updated or deleted. No `version`/`sync_seq` (not a tenant/sync entity). Written through `PlatformAudit::record($action, $businessId, $metadata)`.

## 6. Endpoints

All under `/api/v1/admin`, `auth:api` + `require.platform_admin`, no tenant context.

| Route | Method | Behaviour |
|---|---|---|
| `/admin/tenants` | GET | Every business with its subscription (plan, status, trial/period end) and usage (customers, users). Paginated (`?page=`, default 25/page), newest business first. Bypass read. |
| `/admin/tenants/{id}` | GET | One tenant: business, subscription, usage, and its `subscription_payments` (newest first, `pending` first). Bypass read. 404 if no such business. |
| `/admin/tenants/{id}/suspend` | POST | `switchTo(id)`, set `subscription.status = 'read_only'`. Audit `suspend`. Idempotent (already read_only → 200, no duplicate audit spam: record only on an actual change). |
| `/admin/tenants/{id}/reactivate` | POST | `switchTo(id)`, set status to `active` if `current_period_end` is in the future, else `past_due`. Audit `reactivate`. |
| `/admin/payments/{id}/verify` | POST | Read the payment (bypass) to find its `business_id`; `switchTo` it; `SubscriptionService::activateFromPayment($payment)` (activates plan, extends period, marks payment `verified`). Audit `verify_payment` with `{payment_id, amount, period_months}`. Idempotent (already verified → 200). |
| `/admin/payments/{id}/reject` | POST | `switchTo` the payment's tenant; set `payment.status = 'rejected'` (only from `pending`). Audit `reject_payment`. |
| `/admin/metrics` | GET | `{tenants: N, subscriptions_by_status: {...}, trials_expiring_7d: N, pending_payments: N}`. Bypass reads/aggregates. |

Suspend/verify/reject resolve the target's `business_id` via the bypass connection first, then switch — the two mechanisms compose exactly as §4 describes.

## 7. Billing integration

- **Suspend** sets `subscription.status = 'read_only'`, which the Billing `EnforceActivePlan` (`plan.gate`) middleware already turns into a soft-block on that tenant's domain writes. No new gating logic — Superadmin drives Billing's existing gate. Reactivate lifts it.
- **Verify** calls the exact `SubscriptionService::activateFromPayment()` seam Billing built and unit-tested but wired to no endpoint. Superadmin is that endpoint.
- **Dependency:** Billing must be built before Superadmin (the `subscriptions`, `subscription_payments`, `SubscriptionService`, and `EnforceActivePlan` all pre-exist). Build order: Billing → Superadmin.

## 8. Testing

Pest, existing conventions, plus:
- A **platform-admin token** helper: a `User` with `is_platform_admin = true`, a JWT with no `tid` (or tid ignored).
- The **`pgsql_platform`** connection available in the test env (same DB, the BYPASSRLS role; the role-creation migration runs in the suite).

Coverage:
- **Guard (the security core):** platform admin reaches `/admin/*`; a tenant owner/admin/salesman/accountant is **403** on every route; an unauthenticated request is 401; a user whose flag was just revoked is 403.
- **Cross-tenant read:** `GET /admin/tenants` returns *all* tenants (≥2 businesses) with correct per-tenant usage counts; a tenant with 3 customers shows 3 while a neighbour shows its own.
- **Suspend integration:** after `POST /admin/tenants/{id}/suspend`, that tenant's `POST /customers` is `402` (`read_only`) — proving Superadmin drives Billing's gate — while a second tenant is unaffected; `reactivate` restores writes.
- **Verify/reject:** `verify` flips the subscription to `active`/paid and marks the payment `verified` (idempotent on replay); `reject` sets `rejected` and does not activate.
- **Metrics:** counts reflect seeded tenants/subscriptions/pending payments.
- **Audit:** each mutation writes exactly one `platform_audit_logs` row with the right `action`, `admin_user_id`, `target_business_id`.

Baseline entering this slice is the Billing end state; target roughly **+35** tests.

## 9. Design decisions (rationale)

- **Guard on the flag, live from the DB** — PRD §12 ("guarded by a platform-admin flag, not a business membership"); a live check makes revocation immediate, worth the one query on a low-traffic admin surface.
- **Hybrid read/write** — cross-tenant *reads* must bypass RLS and a single aggregate beats an N-tenant loop; *writes* stay scoped to one tenant through RLS `WITH CHECK`, so a platform bug can't splatter across tenants. The two mechanisms compose: resolve the target via bypass, then `switchTo` and write.
- **A least-privilege read-only BYPASSRLS role, not the superuser** — the migrate connection is superuser and fine for migrations/tests, but a runtime admin-read path should hold only SELECT; a leaked credential then cannot mutate.
- **Audit table is platform-owned, not tenant-scoped** — it records the platform acting *on* tenants; RLS-scoping it to a tenant would be wrong (the platform is the subject, not a tenant).
- **Suspension reuses Billing's `read_only` gate** — no parallel "is suspended" flag; one status field drives both dunning and manual suspension, so there is a single source of truth for "writes paused."
- **Impersonation and export deferred** — each is a substantial, sensitive surface (audited token issuance; DPDP-compliant export) that deserves its own slice; the audit table built here is the foundation impersonation will extend.

**Known risk unchanged:** PgBouncer is not configured; the suite proves RLS/`SET LOCAL` and the BYPASSRLS role against Postgres directly, not transaction pooling in situ.
