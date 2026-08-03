# Superadmin / Platform Console Implementation Plan

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A platform-admin-gated, cross-tenant console API: list tenants with subscription + usage, suspend/reactivate, verify/reject the manual/UPI payments Billing records, and platform metrics — every mutation audited.

**Architecture:** A `require.platform_admin` middleware (over `auth:api`, live `is_platform_admin` check, **no** tenant context) fronts `/api/v1/admin/*`. Cross-tenant **reads** run on a new least-privilege `BYPASSRLS` connection (`pgsql_platform`); **writes** run on the normal connection via `TenantContext::switchTo($targetBusinessId)` so RLS `WITH CHECK` pins each mutation to one tenant. Suspension drives Billing's existing `read_only` gate; verification calls Billing's `SubscriptionService::activateFromPayment()` seam. One platform-owned append-only table, `platform_audit_logs`, records every mutation.

**Tech Stack:** PHP 8.3, Laravel 11, PostgreSQL (RLS + a BYPASSRLS role), Pest.

**Design source:** `docs/superpowers/specs/2026-07-18-superadmin-design.md` (approved). PRD §12 (superadmin), §11 (platform API), §13 (auditability).

**Depends on:** the **Billing slice must be built first** — `subscriptions`, `subscription_payments`, `SubscriptionService::activateFromPayment()`, and the `EnforceActivePlan` (`plan.gate`) middleware all pre-exist. Also the tenancy core (`users.is_platform_admin`, `TenantContext::switchTo`, `TokenService`, the middleware alias system in `bootstrap/app.php`).

---

## Scope

**In scope:** `require.platform_admin` guard; `pgsql_platform` BYPASSRLS read connection (role created by migration); `platform_audit_logs` table + `PlatformAudit` helper; `GET /admin/tenants`, `GET /admin/tenants/{id}`; `POST /admin/tenants/{id}/suspend|reactivate`; `POST /admin/payments/{id}/verify|reject`; `GET /admin/metrics`; the inverse-isolation test suite.

**Out of scope** (per spec §1): impersonation, per-tenant export/backup, a platform UI, automated dunning, and an API to create platform admins (the flag is set out-of-band in v1).

---

## File Structure

```
backend/
  app/
    Http/
      Middleware/RequirePlatformAdmin.php     (new — 'require.platform_admin')
      Controllers/Api/V1/Admin/
        TenantAdminController.php             (new — index, show, suspend, reactivate)
        PaymentAdminController.php            (new — verify, reject)
        MetricsAdminController.php            (new — index)
    Models/
      PlatformAuditLog.php                    (new — platform-owned, no tenant scope)
    Platform/
      PlatformReader.php                      (new — bypass-connection read queries)
      PlatformAudit.php                       (new — record($action,$businessId,$meta))
  config/database.php                          (modified — 'pgsql_platform' connection)
  bootstrap/app.php                            (modified — 'require.platform_admin' alias)
  routes/api.php                               (modified — /admin group)
  database/
    migrations/
      2026_07_19_000001_create_platform_read_role.php     (CREATE ROLE ... BYPASSRLS)
      2026_07_19_000002_create_platform_audit_logs_table.php
    factories/
      PlatformAuditLogFactory.php
  .env.example                                 (modified — DB_PLATFORM_USERNAME/PASSWORD)
  README.md                                    (modified — Superadmin API)
  tests/
    Feature/Admin/
      PlatformGuardTest.php
      TenantListTest.php
      TenantSuspendTest.php
      PaymentVerifyTest.php
      MetricsTest.php
      AuditLogTest.php
    Pest.php                                   (modified — platformAdminToken() helper if shared)
```

**Conventions inherited (not re-derived):** UUID PKs via `HasUuids`; controllers return `response()->json(...)`; middleware aliases live in `bootstrap/app.php`; RLS-bypassing setup rows in tests use `Model::on('pgsql_migrate')`; `TenantContext::switchTo($id)` sets `app.current_tenant`; `TokenService::issue($user)` (no membership) mints a tid-less JWT.

---

## Task 1: The `pgsql_platform` BYPASSRLS read connection

**Files:** migration `…000001_create_platform_read_role…`, `config/database.php`, `.env.example`, test `tests/Feature/Admin/PlatformConnectionTest.php`

- [ ] **Migration** (runs on `pgsql_migrate`, the superuser) — idempotently create a least-privilege read role and grant SELECT:
  ```php
  DB::connection('pgsql_migrate')->unprepared(<<<SQL
      DO $$
      BEGIN
        IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'vyapar_platform_ro') THEN
          CREATE ROLE vyapar_platform_ro LOGIN PASSWORD 'platform_ro_pw' BYPASSRLS;
        END IF;
      END
      $$;
      GRANT USAGE ON SCHEMA public TO vyapar_platform_ro;
      GRANT SELECT ON ALL TABLES IN SCHEMA public TO vyapar_platform_ro;
      ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO vyapar_platform_ro;
  SQL);
  ```
  `down()` — `DROP OWNED BY vyapar_platform_ro;` then `DROP ROLE IF EXISTS vyapar_platform_ro;` (guarded).
- [ ] **`config/database.php`** — add a `'pgsql_platform'` connection cloning `'pgsql'` but with `'username' => env('DB_PLATFORM_USERNAME', 'vyapar_platform_ro')`, `'password' => env('DB_PLATFORM_PASSWORD', 'platform_ro_pw')`, same host/port/database. Add `DB_PLATFORM_USERNAME`/`DB_PLATFORM_PASSWORD` to `.env.example`.
- [ ] **Test** — seed a customer for business B on `pgsql_migrate`; **without** setting any tenant, `DB::connection('pgsql_platform')->table('customers')->count()` is ≥ 1 (RLS bypassed), whereas the same count on the default `pgsql` connection with no tenant set is 0. → PASS.
- [ ] **Commit** — `feat: add least-privilege BYPASSRLS platform read connection`.

The role is SELECT-only: it can read across tenants but cannot mutate. Writes are the normal connection's job (Task 6+).

---

## Task 2: platform_audit_logs model, migration, factory

**Files:** migration `…000002…`, `app/Models/PlatformAuditLog.php`, factory, test folded into `AuditLogTest` (Task 9).

- [ ] **Migration** — `id` uuid PK; `foreignId('admin_user_id')->constrained('users')`; `string('action', 40)`; `foreignUuid('target_business_id')->nullable()->constrained('businesses')`; `jsonb('metadata')->nullable()`; `timestamp('created_at')->nullable()`; `index(['target_business_id','created_at'])`. **No RLS** — platform-owned, records the platform acting on tenants. No `updated_at` (append-only; use `$table->timestamp('created_at')` alone).
- [ ] **Model** — `use HasUuids;` `public $timestamps = false;` (only `created_at`, set explicitly). `$fillable = ['admin_user_id','action','target_business_id','metadata'];` casts `metadata` → `array`. **No** `BelongsToTenant` — this is not a tenant table. On create, set `created_at = now()`.
- [ ] **Factory** — `admin_user_id` => User::factory(), `action` `'suspend'`, `target_business_id` => Business::factory(), `metadata` => `['from_status' => 'active', 'to_status' => 'read_only']`, `created_at` => now().
- [ ] **Commit** — `feat: add platform_audit_logs append-only model`.

Append-only: rows are inserted, never updated/deleted. No `version`/`sync_seq` — not a tenant/sync entity.

---

## Task 3: PlatformAudit helper

**Files:** `app/Platform/PlatformAudit.php`, test `tests/Unit/PlatformAuditTest.php`

- [ ] **`PlatformAudit::record(string $action, ?string $businessId, array $metadata = []): PlatformAuditLog`** — `PlatformAuditLog::create(['admin_user_id' => app('tenant.user_id'), 'action' => $action, 'target_business_id' => $businessId, 'metadata' => $metadata, 'created_at' => now()])`. (`app('tenant.user_id')` is set by `SetTenantContext` for any authenticated request, tenant or not — confirm it is populated on platform routes; if platform routes don't run `tenant.context`, read the id from `auth()->id()` instead.)
- [ ] **Decide the actor source** — platform routes do **not** run `tenant.context` (spec §3), so `app('tenant.user_id')` is unavailable there. Use `auth()->id()` for `admin_user_id`. Implement `record()` with `auth()->id()`.
- [ ] **Test** — `PlatformAudit::record('suspend', $businessId, ['x' => 1])` (with an authenticated platform user acting) writes one row with the right `admin_user_id`, `action`, `target_business_id`, and `metadata['x'] === 1`. → PASS.
- [ ] **Commit** — `feat: add PlatformAudit helper for the mutation trail`.

---

## Task 4: require.platform_admin middleware

**Files:** `app/Http/Middleware/RequirePlatformAdmin.php`, `bootstrap/app.php`, routes stub, test `tests/Feature/Admin/PlatformGuardTest.php`

- [ ] **Middleware** — `handle($request, Closure $next)`: `$id = auth()->id();` if null → `abort(401)`. `$user = \App\Models\User::find($id);` if `! $user || ! $user->is_platform_admin` → `abort(403, 'Platform admin only.')`. Else `return $next($request);`. Live DB check — a just-revoked flag 403s on the next request.
- [ ] **Alias** — in `bootstrap/app.php` `$middleware->alias([...])`, add `'require.platform_admin' => \App\Http\Middleware\RequirePlatformAdmin::class,`.
- [ ] **Routes stub** — add an `/admin` group in `routes/api.php`:
  ```php
  Route::prefix('v1')->group(function () {
      Route::middleware(['auth:api', 'require.platform_admin'])->prefix('admin')->group(function () {
          Route::get('ping', fn () => response()->json(['ok' => true])); // temporary probe
      });
  });
  ```
  (This group is **outside** the tenant `auth:api`+`tenant.context` group — platform routes carry no tenant context.)
- [ ] **Test** — a platform-admin token (user with `is_platform_admin = true`, `TokenService::issue($user)` — no tid) reaches `GET /api/v1/admin/ping` (200); a regular owner token → 403; a salesman/accountant/admin token → 403; no token → 401; a platform admin whose flag is then set false → 403 on the next call. → PASS.
- [ ] **Commit** — `feat: add require.platform_admin guard for the console`.

Write the `platformAdminToken()` and `ownerToken()` helpers in this test; reuse them in later Admin tests (or lift to `Pest.php` if shared).

---

## Task 5: PlatformReader (cross-tenant reads)

**Files:** `app/Platform/PlatformReader.php`, test `tests/Unit/PlatformReaderTest.php`

- [ ] **`PlatformReader`** — all reads on `DB::connection('pgsql_platform')`, RLS bypassed:
  - `tenants(int $page, int $perPage = 25): array` — from `businesses` newest first, left-joined to `subscriptions`; each row `{id, name, city, plan, status, trial_ends_at, current_period_end}`. Paginated (LIMIT/OFFSET).
  - `usageFor(string $businessId): array` — `['customers' => count of customers where business_id AND archived_at is null, 'users' => count of memberships where business_id]` on the bypass connection.
  - `tenant(string $businessId): ?array` — one business + its subscription, or null.
  - `paymentsFor(string $businessId): array` — `subscription_payments` for the business, `pending` first then newest.
  - `payment(string $paymentId): ?array` — one payment row (incl. its `business_id`), or null.
  - `metrics(): array` — `['tenants' => count businesses, 'subscriptions_by_status' => status=>count, 'trials_expiring_7d' => count subscriptions status='trialing' AND trial_ends_at BETWEEN now() AND now()+7d, 'pending_payments' => count subscription_payments status='pending']`.
- [ ] **Test** — seed two businesses (A with 3 customers + 1 membership, B with 1 customer) and subscriptions on `pgsql_migrate`; `tenants(1)` returns both (no tenant set, bypass works); `usageFor(A)` is `{customers:3, users:1}` and `usageFor(B)` is `{customers:1, ...}`; `metrics()['tenants']` is ≥ 2 and `subscriptions_by_status` sums correctly. → PASS.
- [ ] **Commit** — `feat: add PlatformReader for cross-tenant console reads`.

Reads never set a tenant — the BYPASSRLS role is the whole point. Keep every query in this one class so the bypass surface is small and reviewable.

---

## Task 6: Tenant list & detail

**Files:** `app/Http/Controllers/Api/V1/Admin/TenantAdminController.php` (`index`, `show`), routes, test `tests/Feature/Admin/TenantListTest.php`

- [ ] **`index`** `GET /admin/tenants` — `$page = (int) request('page', 1);` `$rows = $reader->tenants($page);` map each to include `usage` via `$reader->usageFor($row['id'])`; return `{tenants: [...], page}`.
- [ ] **`show`** `GET /admin/tenants/{id}` — `$t = $reader->tenant($id);` if null → `abort(404)`. Return `{tenant: $t, usage: $reader->usageFor($id), payments: $reader->paymentsFor($id)}`.
- [ ] **Routes** — replace the `admin/ping` stub with `Route::get('tenants', [TenantAdminController::class, 'index']); Route::get('tenants/{id}', [TenantAdminController::class, 'show']);`.
- [ ] **Test** — seed 2 tenants with differing usage; a platform admin `GET /admin/tenants` sees **both** with correct per-tenant `usage`; `GET /admin/tenants/{A}` returns A's subscription + usage + payments; an unknown id → 404; a regular owner → 403 (guard). → PASS.
- [ ] **Commit** — `feat: add admin tenant list and detail (cross-tenant read)`.

---

## Task 7: Suspend & reactivate

**Files:** `TenantAdminController.php` (`suspend`, `reactivate`), routes, test `tests/Feature/Admin/TenantSuspendTest.php`

- [ ] **`suspend`** `POST /admin/tenants/{id}/suspend` — resolve the business via `$reader->tenant($id)` (404 if none). `TenantContext::switchTo($id);` load `Subscription::firstOrFail()` (now tenant-scoped to the target); if `status !== 'read_only'`, set `status = 'read_only'`, `save()`, and `PlatformAudit::record('suspend', $id, ['to_status' => 'read_only'])`. Return the subscription. Idempotent: already read_only → 200, no audit row.
- [ ] **`reactivate`** `POST /admin/tenants/{id}/reactivate` — `switchTo($id)`; `$sub = Subscription::firstOrFail();` `$to = ($sub->current_period_end && $sub->current_period_end->isFuture()) ? 'active' : 'past_due';` if `status !== $to`, set + save + `PlatformAudit::record('reactivate', $id, ['to_status' => $to])`. Return the subscription.
- [ ] **Routes** — `Route::post('tenants/{id}/suspend', ...); Route::post('tenants/{id}/reactivate', ...);`.
- [ ] **Test** — seed tenant A (trialing) + tenant B; `POST /admin/tenants/{A}/suspend` → 200, A's subscription `status='read_only'`, one `suspend` audit row; **A's** `POST /api/v1/customers` (owner token for A) is now `402` (`code='read_only'`) — proving Superadmin drives Billing's gate — while **B's** owner `POST /customers` is 201 (unaffected); `POST /admin/tenants/{A}/reactivate` → A `past_due`, A's writes work again; a second suspend is idempotent (still one write, no duplicate audit). → PASS.
- [ ] **Commit** — `feat: add admin suspend and reactivate (drives billing gate)`.

The write path `switchTo`-es exactly the target business, so RLS `WITH CHECK` guarantees the update touches only that tenant.

---

## Task 8: Verify & reject payments

**Files:** `app/Http/Controllers/Api/V1/Admin/PaymentAdminController.php` (`verify`, `reject`), routes, test `tests/Feature/Admin/PaymentVerifyTest.php`

- [ ] **`verify`** `POST /admin/payments/{id}/verify` — `$p = $reader->payment($id);` if null → 404. `TenantContext::switchTo($p['business_id']);` `$payment = SubscriptionPayment::findOrFail($id);` if `status === 'verified'` → return 200 (idempotent). `$sub = app(SubscriptionService::class)->activateFromPayment($payment);` `PlatformAudit::record('verify_payment', $p['business_id'], ['payment_id' => $id, 'amount' => $payment->amount, 'period_months' => $payment->period_months]);` return `{subscription: $sub, payment: $payment->fresh()}`.
- [ ] **`reject`** `POST /admin/payments/{id}/reject` — `$p = $reader->payment($id);` 404 if null. `switchTo($p['business_id']);` `$payment = SubscriptionPayment::findOrFail($id);` if `status === 'pending'`, set `status = 'rejected'`, `save()`, `PlatformAudit::record('reject_payment', $p['business_id'], ['payment_id' => $id])`. Return the payment. (Non-pending → 422 "already resolved".)
- [ ] **Routes** — `Route::post('payments/{id}/verify', ...); Route::post('payments/{id}/reject', ...);`.
- [ ] **Test** — seed tenant A on trial with a `pending` pro payment (`amount 499`, `period_months 1`); `POST /admin/payments/{id}/verify` → A's subscription `status='active'`, `plan='pro'`, `current_period_end ≈ now()+1mo`, payment `status='verified'`; a replay → 200 and the period is **not** extended again; a `reject` on a fresh pending payment sets `rejected` and leaves the subscription unchanged; one audit row per action; a regular owner → 403. → PASS.
- [ ] **Commit** — `feat: add admin verify and reject of subscription payments`.

`verify` is the endpoint that finally drives Billing's `activateFromPayment()` seam.

---

## Task 9: Metrics & audit read-back

**Files:** `app/Http/Controllers/Api/V1/Admin/MetricsAdminController.php` (`index`), routes, tests `tests/Feature/Admin/MetricsTest.php`, `tests/Feature/Admin/AuditLogTest.php`

- [ ] **`index`** `GET /admin/metrics` — return `$reader->metrics()`.
- [ ] **Route** — `Route::get('metrics', [MetricsAdminController::class, 'index']);`.
- [ ] **Metrics test** — seed 3 businesses (statuses: trialing with trial_ends_at in 3 days, active, read_only) and 2 pending payments; `GET /admin/metrics` returns `tenants ≥ 3`, `subscriptions_by_status` counts each, `trials_expiring_7d ≥ 1`, `pending_payments = 2`; regular owner → 403. → PASS.
- [ ] **Audit test** — perform a suspend, a reactivate, and a verify (reusing the Task 7/8 flows) and assert `platform_audit_logs` holds exactly three rows with actions `suspend`/`reactivate`/`verify_payment`, each carrying the acting admin's `admin_user_id` and the right `target_business_id`. → PASS.
- [ ] **Commit** — `feat: add admin metrics and prove the audit trail`.

---

## Task 10: Full suite, docs, close-out

**Files:** `backend/README.md`, this plan.

- [ ] **Full suite** — `php artisan test`: green. Baseline entering this slice is the Billing end state (~278); every task only adds tests.
- [ ] **README** — a "Superadmin / Platform Console API" section: the platform-admin guard (flag, not membership; live check), the hybrid read/write model (BYPASSRLS reads + `switchTo` writes), the route table, suspend↔Billing-gate and verify↔`activateFromPayment` integrations, and the audit trail.
- [ ] **Close-out** — tick every checkbox, add a status table (task → commit) and a Known Gaps section (impersonation + per-tenant export deferred to their own slices; no create-platform-admin API; automated dunning is Phase 2; PgBouncer unchanged).
- [ ] **Commit** — `docs: document the superadmin API and close out the plan`.

---

## Self-Review Notes

**Spec coverage** — §3 guard → Task 4; §4 hybrid access → Tasks 1 (connection), 5 (reads), 7/8 (scoped writes); §5 audit table → Tasks 2, 3; §6 endpoints → Tasks 6 (list/detail), 7 (suspend/reactivate), 8 (verify/reject), 9 (metrics); §7 Billing integration → Tasks 7 (gate), 8 (activateFromPayment); §8 testing incl. inverse-isolation → Tasks 4, 6, 7, 8, 9.

**Deliberate design decisions** (spec §9): guard on the live flag; hybrid read/write (bypass reads, tenant-scoped writes); a SELECT-only BYPASSRLS role, not the superuser; a platform-owned (non-tenant) audit table; suspension reuses Billing's `read_only` gate; impersonation/export deferred.

**Type/name consistency** — `PlatformReader` methods (`tenants`, `usageFor`, `tenant`, `paymentsFor`, `payment`, `metrics`), `PlatformAudit::record($action,$businessId,$metadata)`, and `SubscriptionService::activateFromPayment($payment)` (from the Billing plan) are used consistently across Tasks 5–9.

**Known risk unchanged:** PgBouncer is not configured; the suite proves RLS/`SET LOCAL` and the BYPASSRLS role against Postgres directly, not transaction pooling in situ.

**Test-count target:** Billing end state + roughly 3(T1)+4(T4)+4(T5)+4(T6)+5(T7)+5(T8)+2(T9 metrics)+1(T9 audit)+… → **~+33 passing**. A materially lower number means tasks were skipped.
