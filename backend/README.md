# VyaparBook Backend

Laravel 11 API for VyaparBook's tenancy & auth core. No Docker — MySQL and Redis
run as native local services.

## Prerequisites

- PHP 8.3, Composer, `php8.3-mysql`
- MySQL 8
- Redis

## One-time MySQL setup

Run as root (`sudo mysql`). Both host patterns are needed: MySQL resolves
`127.0.0.1` back to `localhost`, so a grant to `'%'` alone is not matched and you
get a confusing `Access denied for user ...@'localhost'` even though `DB_HOST` is
`127.0.0.1`.

```sql
CREATE DATABASE IF NOT EXISTS vyaparbook
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- phpunit.xml points the suite at its own database so a test run never wipes
-- development data.
CREATE DATABASE IF NOT EXISTS vyaparbook_test
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'vyaparbook_app'@'localhost' IDENTIFIED BY 'change-me';
CREATE USER IF NOT EXISTS 'vyaparbook_app'@'%'         IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON vyaparbook.*      TO 'vyaparbook_app'@'localhost';
GRANT ALL PRIVILEGES ON vyaparbook.*      TO 'vyaparbook_app'@'%';
GRANT ALL PRIVILEGES ON vyaparbook_test.* TO 'vyaparbook_app'@'localhost';
GRANT ALL PRIVILEGES ON vyaparbook_test.* TO 'vyaparbook_app'@'%';

-- SELECT and nothing else. The superadmin console reads across every tenant, so
-- this grant is what makes it physically unable to mutate a shop's data.
CREATE USER IF NOT EXISTS 'vyapar_platform_ro'@'localhost' IDENTIFIED BY 'platform_ro_pw';
CREATE USER IF NOT EXISTS 'vyapar_platform_ro'@'%'         IDENTIFIED BY 'platform_ro_pw';
GRANT SELECT ON vyaparbook.*      TO 'vyapar_platform_ro'@'localhost';
GRANT SELECT ON vyaparbook.*      TO 'vyapar_platform_ro'@'%';
GRANT SELECT ON vyaparbook_test.* TO 'vyapar_platform_ro'@'localhost';
GRANT SELECT ON vyaparbook_test.* TO 'vyapar_platform_ro'@'%';
```

Verify that decimals arrive as PHP **strings**, not floats — every rupee in this
system is a decimal string through bcmath, and floats silently corrupt khatas:

```bash
php artisan tinker --execute="var_dump(gettype(DB::select('SELECT CAST(1.5 AS DECIMAL(12,2)) d')[0]->d));"
# => string(6) "string"
```

If it prints `double`, `PDO::ATTR_EMULATE_PREPARES` is not taking effect — stop
and fix it. `tests/Feature/Database/DecimalFidelityTest.php` pins this.

## App setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
# Fill in DB_* in .env to match the setup above.
php artisan migrate --seed
php artisan serve
```

In a separate terminal, run the queue worker:
```bash
cd backend
php artisan queue:work
```

On WSL, the native services do not survive a restart and must be started by hand:
```bash
sudo service mysql start && sudo service redis-server start
```

## Running tests

```bash
cd backend
php artisan test
```

The suite does not use Laravel's `RefreshDatabase` — see `tests/RefreshesTenantDatabase.php`
for why. It migrates once per run and clears the tables between tests, so every
write genuinely commits — ~900 tests rely on that.

## Notes

- Migrations and the app share ONE connection. The old two-role split existed
  only so the app role could not bypass row-level security; MySQL has no RLS, so
  it protected nothing.
- **Tenant isolation is a single application layer.** `app/Traits/BelongsToTenant.php`
  scopes every Eloquent query and FAILS CLOSED — it throws rather than returning
  every tenant's rows when no tenant is bound. Cross-tenant work goes through
  `Tenancy::withoutTenant()` (five sanctioned sites; `grep -rn withoutTenant`
  is the audit). A test-environment query tripwire catches raw builders, which a
  global scope structurally cannot reach — as do `$model->fresh()` and
  `->refresh()`, which build their queries without scopes.
  `memberships` is deliberately outside the scope: it is keyed by user as
  legitimately as by business, which is what makes login-before-tenant work.
  `businesses`, `users`, `invites`, and `otp_codes` are not tenant-owned.

## Catalog API

All catalog routes require a selected tenant (`auth:api` + `tenant.context` +
`require.tenant`).

| Route | Roles | Notes |
|---|---|---|
| `GET /api/v1/catalog` | any | Whole catalog in one payload; `?include_archived=1` for the management view |
| `POST /api/v1/catalog/seed` | owner, admin | One-time onboarding; 409 if the catalog is non-empty |
| `POST\|PATCH\|DELETE /api/v1/products/{id}` | owner, admin | `DELETE` archives, it does not delete |
| `POST /api/v1/products/{id}/restore` | owner, admin | |
| …same shape for `/pack-sizes` and `/product-packs` | owner, admin | |

Templates live in `database/catalog_templates/*.php`. Adding a vertical is a new
file — no migration, no code change. `blank` is a valid template that seeds nothing.

Two behaviours that look like bugs and are not:

- **A cross-tenant row returns 404, not 403.** The tenant scope adds a
  `business_id` predicate, so `findOrFail` genuinely finds nothing. This also
  avoids confirming that another tenant's id is real.
- **`Rule::unique('pack_sizes', 'label')` has no tenant clause.** Validation runs
  with the tenant bound, so the scope has already narrowed the table to one
  business.

Archiving is evaluated at read time and never cascaded: a product pack is hidden
when it, its product, or its pack size is archived, but archiving a product does
not write `archived_at` onto its packs. This keeps restore lossless.

## Khata & Sync API

The transactional core — customers, an append-only sales/payments ledger, and the
offline sync endpoints. All routes require a selected tenant (`auth:api` +
`tenant.context` + `require.tenant`).

| Route | Roles | Notes |
|---|---|---|
| `GET /api/v1/khata` | any | Every customer with its outstanding; `?include_archived=1` for the full view |
| `GET /api/v1/khata/{id}` | any | One customer's time-ordered statement with a running balance |
| `POST\|PATCH\|DELETE /api/v1/customers/{id}` | owner, admin, salesman | `DELETE` archives; idempotent create by `uuid` |
| `POST /api/v1/sales` | owner, admin, salesman | Idempotent by `uuid`; freezes each line's rate |
| `POST /api/v1/sales/{id}/void` | owner, admin | Writes a reversing entry; never mutates the original |
| `POST /api/v1/payments` | owner, admin, salesman, accountant | Idempotent by `uuid` |
| `POST /api/v1/payments/{id}/reverse` | owner, admin | Reversing entry |
| `POST /api/v1/sync/push` | any (per-mutation role check) | Drains the offline outbox; see below |
| `GET /api/v1/sync/pull?since=` | any | Delta since a `sync_seq` cursor |

Four rules that look surprising and are deliberate:

- **The ledger is append-only.** A sale or payment is immutable once written. A
  void or reversal is a *new* row whose `reverses_id` points at the original and
  whose amounts are negated. `outstanding = opening_balance + Σ sale.total −
  Σ payment.amount` is therefore always recomputable, and reversals net themselves
  out. Nothing is ever edited or deleted.
- **`sale_lines.rate` is a snapshot.** It is copied from the product pack at sale
  time and stored, never read live — a two-year-old sale reflects the price then,
  not today's catalog.
- **Every write is idempotent by `(business_id, uuid)`.** A sale/payment/customer
  retried over a flaky link posts exactly once; the replay returns the existing
  row (`200`) instead of creating a duplicate (`201`). The REST endpoints and
  `sync/push` share one write path (`LedgerWriter`), so online and offline creates
  cannot drift.
- **A cross-tenant reference returns 404, not 403** (the scope hides the row), and
  `sync/push` rejects any mutation whose `tenant_id` ≠ the session tenant at the
  app layer — reported per item, never fatal to
  the batch.

Delta sync uses a per-tenant monotonic `sync_seq` (a counter row stamped on
every insert/update via `HasSyncSequence`). `pull` returns rows with `sync_seq >
since` ordered by `sync_seq`, plus the new cursor; the tenant scope guarantees the response
holds only the caller's tenant. Archived rows ride the delta so the client learns
to hide them.

## Stock & Production API

Raw-material **stock** (a signed-movement ledger) and **production** (batches that
consume materials and draw stock down). Same tenant-isolated, append-only,
sync-ready foundation as the khata. All routes require a selected tenant
(`auth:api` + `tenant.context` + `require.tenant`).

**Owner/admin only — reads included.** Unlike the catalog and khata (open reads),
*every* stock and production endpoint is gated by `StockPolicy::manage()`.
Salesman and accountant have no access at all, GETs included (PRD §7). This is the
one module that departs from the open-reads rule.

| Route | Roles | Notes |
|---|---|---|
| `GET /api/v1/stock` | owner, admin | Every material with `on_hand`, `reorder_level`, `below_reorder`; `?include_archived=1` for the full view |
| `GET /api/v1/stock/{id}` | owner, admin | One material's movement ledger with a running on-hand |
| `POST\|PATCH\|DELETE /api/v1/raw-materials/{id}` | owner, admin | `DELETE` archives; idempotent create by `uuid` |
| `POST /api/v1/stock-movements` | owner, admin | Record an `in`/`out`/`adjust`; idempotent by `uuid` |
| `POST /api/v1/production` | owner, admin | Create a batch; consumes materials + draws stock down; idempotent by `uuid` |
| `GET /api/v1/production` | owner, admin | Batches newest first |
| `GET /api/v1/production/{id}` | owner, admin | One batch with its material consumptions |
| `GET /api/v1/sync/pull?since=` | owner, admin (stock rows) | The four stock/production tables ride the delta; withheld by role for salesman/accountant |

Rules that look surprising and are deliberate:

- **Stock on hand is `Σ qty`, never a stored number.** `stock_movements.qty` is the
  *signed* effect on stock. The API takes a `kind` and a positive magnitude and
  derives the sign — `in` → `+qty`, `out` → `−qty` — while `adjust` takes a signed
  delta directly. So `Σ qty` is the on-hand total and an `out` can never raise
  stock. On-hand is always recomputable, exact via bcmath at scale 3.
- **The ledger is append-only; correct with an `adjust`.** A movement is immutable.
  A physical recount is a *new* `adjust` movement, not an edit — history and offline
  replay are preserved, exactly as the khata voids with a reversing row.
- **Completing a batch draws stock down through the same ledger.** `POST /production`
  writes, in one transaction, a `MaterialConsumption` per line *and* an `out`
  `StockMovement` (signed negative) tagged with `production_batch_id`. So on-hand
  drops through the very ledger `GET /stock` reads — never a separate number that
  can drift — and each draw-down is traceable back to its batch.
- **Negative stock is allowed.** Over-consumption is recorded and flagged
  (`below_reorder` / a negative `on_hand`), never blocked — "soft-block, never data
  loss" (PRD §8). Hard-blocking would also break offline.
- **Every top-level write is idempotent by `(business_id, uuid)`.** A retried
  material, movement or batch posts exactly once; the replay returns the existing
  row (`200`) instead of a duplicate (`201`), and a batch replay performs no second
  draw-down. `material_consumptions` is a child of its batch (no `uuid`), like
  `sale_lines`.
- **A cross-tenant reference returns 404, not 403** (the scope hides the row).

## Billing & Subscription API

Every tenant has one **subscription** (Free/Pro) with a **14-day trial** and a
status. Plan limits are enforced **server-side as a soft-block** — a `402` upgrade
prompt, never data loss (PRD §8). v1 is manual/UPI (no gateway). Billing is
**owner only** (PRD §7) — stricter than stock/production; admin is excluded too.

| Route | Roles | Notes |
|---|---|---|
| `GET /api/v1/billing` | owner | Effective plan, status, trial, limits, live usage, over-limit flags, payment history |
| `POST /api/v1/billing/payments` | owner | Record a manual/UPI payment as `pending`; idempotent by `(business_id, uuid)`; 18% GST computed via bcmath |

Both sit **outside** the read-only write gate — an owner in dunning must still be
able to view billing and pay.

**Plans (in code, `PlanCatalog` — not a table):** `free` → 50 customers / 1 user /
no stock; `pro` → unlimited customers / 5 users / `stock_production`. A `null` limit
means unlimited. A live **trial grants Pro entitlement**; `effectivePlan()` is the
single resolver (trial → Pro, anything lapsed → Free floor).

Rules that are deliberate:

- **Trial provisioned on business creation.** `BusinessController::store` calls
  `SubscriptionService::provisionTrial()` in the same transaction as the owner
  membership — every new business starts on a 14-day Pro trial.
- **Soft-block, three enforcement points.** Over the customer cap → `402`
  (`resource: customers`); past the user seats → `402` (`resource: users`); stock/
  production without the feature → `402` (`resource: stock_production`). All share
  the `PlanGuard` `{code: plan_limit, resource, upgrade: true}` shape.
- **Idempotent replays are never blocked.** A retried customer create with an
  existing `uuid` creates nothing, so it passes even on a maxed plan.
- **Read-only (dunning) gate.** When a subscription is `read_only`, the `plan.gate`
  middleware pauses domain **writes** with `402 {code: read_only}` while GET reads
  (and billing view/pay) still flow.
- **Fail-open resolution.** A tenant with no subscription row (legacy/pre-billing)
  is treated as a fresh trial, never hard-locked — billing must not take core
  operations down over a missing row. New businesses always get a real row.
- **Payments are an append-only ledger.** A wrong payment is `rejected` and a new
  one recorded, never edited — like the khata/stock ledgers. Verification (`pending`
  → `verified`, which activates the plan via `SubscriptionService::activateFromPayment()`)
  is deferred to the Superadmin console; the service seam is built and tested here.

## Tenant Import (Excel/CSV onboarding)

An operator-run Artisan command that ingests a shop's **customers** (with opening
outstanding) and **raw materials** (with current stock) from a CSV into one tenant,
so its khata continues seamlessly from day one. This is a migration tool, not a
self-serve upload — it runs on the box, against a business id you already have.

```bash
php artisan tenant:import {business_id} {type} {path} [--dry-run]
#   type = customers | raw-materials
php artisan tenant:import 0f9e… customers     /tmp/customers.csv
php artisan tenant:import 0f9e… raw-materials /tmp/materials.csv --dry-run
```

**Expected CSV columns** (header row required; column order is free):

| type | columns |
|---|---|
| `customers` | `name` (required), `village`, `phone`, `opening_balance` (≥ 0, default 0) |
| `raw-materials` | `name` (required), `unit` (one of kg, litre, piece, gram, ml, packet; default kg), `reorder_level` (≥ 0), `opening_stock` (≥ 0) |

Rules that are deliberate:

- **Opening balance seeds the खाता.** `opening_balance` lands on `Customer.opening_balance`,
  so `outstanding = opening_balance + Σ sales − Σ payments` carries the shop's बाकी
  forward from the first day.
- **Opening stock is one correctable movement.** A material's `opening_stock` becomes a
  single `in` `StockMovement` (`note = "Opening stock (import)"`). Re-importing with a new
  figure **corrects that one movement** rather than stacking another `in` — so a miscount
  is fixed by re-running, never double-counted.
- **Re-runs are safe (idempotent).** Every row derives a deterministic UUIDv5 natural key
  from the business id + a normalised name (+ village for customers). A second import of
  the same sheet **updates in place** via the existing `(business_id, uuid)` unique — no
  duplicates, no schema change.
- **Continue on error, non-zero exit.** Invalid rows are reported (`Row N: <reason>`) and
  skipped; the valid rows still apply; the command exits **1** when any row failed so a
  wrapping script notices. A clean run exits **0**.
- **`--dry-run` validates and tallies without persisting** — the whole import runs inside a
  transaction that is rolled back, so you see the `Created/Updated/Skipped` report and every
  error with zero writes.
- **Single-tenant, scoped.** The importer opens one transaction, binds the target
  tenant (the `TenantAwareJob` pattern), then commits — writes are confined to that
  tenant by `BelongsToTenant`.
