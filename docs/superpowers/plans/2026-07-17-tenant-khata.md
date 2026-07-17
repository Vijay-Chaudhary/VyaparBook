# Tenant Khata Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build VyaparBook's transactional core — `Customer`, `Sale`/`SaleLine`, `Payment` as an **append-only money ledger** with per-customer outstanding, plus the **offline sync** endpoints (idempotent push, delta pull) that PRD §9 calls "the genuinely hard part." Every table carries RLS + the `BelongsToTenant` app scope; every mutation is idempotent by `(business_id, uuid)`; nothing is ever mutated in place — corrections are new reversing entries.

**Architecture:** Four new tenant-owned tables in the existing Laravel 11 backend, each with a flat RLS policy (`business_id = current_setting('app.current_tenant')`) plus the `BelongsToTenant` global scope, following the catalog slice exactly (`docs/superpowers/plans/2026-07-15-tenant-catalog.md`). All routes sit behind `auth:api` + `tenant.context` + `require.tenant`; `SetTenantContext` has already opened the request transaction and set the tenant GUC before any khata code runs. Two ledger-specific invariants drive the design:

- **Append-only.** A `Sale`/`Payment` row is immutable once written. A void or correction is a *new* row whose `reverses_id` points at the original and whose amounts are negated. Outstanding is therefore always `opening_balance + Σsale.total − Σpayment.amount` — reversals net themselves out and no historical row is ever touched. This is what makes offline conflict-free (PRD §9).
- **Price snapshot.** `sale_lines.rate` is captured from the `product_pack` at sale time and stored, never read live. A sale from two years ago must reflect the price then, not today's catalog.

**Offline sync:** each syncable row carries a client-generated `uuid` (idempotency: `(business_id, uuid)` unique) and a globally monotonic `sync_seq` (delta cursor). Push derives the tenant from the membership and **rejects any mutation whose `tenant_id` ≠ the session tenant** (belt-and-suspenders with RLS `WITH CHECK`). Pull returns rows with `sync_seq > cursor`, ordered by `sync_seq`; RLS guarantees the response holds only the caller's tenant even if a query is imperfect.

**Testing:** Pest, following the catalog/tenancy conventions exactly — no `RefreshDatabase` (see `docs/superpowers/specs/2026-07-04-tenancy-auth-core-design.md` §7), `RefreshesTenantDatabase` via `tests/Pest.php`, and `Model::on('pgsql_migrate')` (superuser, bypasses RLS) for setup rows. Money assertions compare exact decimal **strings**, never floats.

**Tech Stack:** PHP 8.3, Laravel 11, PostgreSQL (RLS + a global sequence for `sync_seq`), Pest, bcmath (already present — used for exact rupee math in `CatalogService`).

**Design source:** no standalone spec file — the design is folded into this plan. Domain intent is PRD §7 (RBAC), §9 (offline ledger), §10 (`Customer`/`Sale`/`SaleLine`/`Payment` sketch), §11 (API). The Django sketch in §10 is superseded here by the Eloquent/migration model below.

**Depends on:** the tenancy/auth core and the catalog slice — `SetTenantContext`, `RequireTenant`, `BelongsToTenant`, `HasVersion`, `CatalogPolicy`'s pattern, `TokenService`, the `pgsql`/`pgsql_migrate` split, and the `product_packs` table (sale lines reference it).

---

## Scope

**In scope:**
- `Customer` (name, village, phone, opening balance), CRUD + archive/restore
- `Sale` + `SaleLine` — idempotent create, append-only void via reversing entry, price snapshot
- `Payment` — idempotent record, append-only reversal
- `KhataService` — outstanding (exact decimal) + ledger merge with running balance
- `KhataPolicy` — the PRD §7 role matrix for sales/voids/payments/reads
- `GET /khata` (all customers, outstanding) and `GET /khata/{customer}` (ledger)
- `POST /sync/push` (idempotent batch, tenant-mismatch rejection) and `GET /sync/pull?since=` (delta)
- RLS + `BelongsToTenant` on all four tables; DB-level RLS proof; cross-tenant leak coverage

**Out of scope** (each its own later slice):
- Stock / raw material / production (PRD §10 `RawMaterial`, `StockMovement`, `ProductionBatch`)
- Any frontend / Dexie outbox — this slice ships the *server* contract sync will speak to
- Redis-cached outstanding (PRD §10) — computed on read here; caching is a later optimization, noted where it lands
- Billing/plan limits (PRD §8, e.g. ≤50 customers on Free) — no plan-gating logic in this slice
- Excel/CSV customer import (PRD §5 step 4) — separate slice; this slice creates the `customers` table it will target

---

## Two foundations this slice adds before any domain table

1. **A global `sync_seq` sequence + `HasSyncSequence` trait.** Delta pull needs a *total order* across a tenant's rows. `HasVersion`'s per-row counter can't serve — it starts at 1 on every row, so it isn't monotonic across rows. A global Postgres sequence stamped on every insert/update gives one monotonic stream; RLS narrows the pull to one tenant. No explicit grant is needed: `create_app_role` already runs `ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES`, so a sequence created later by the same privileged role is usable by the restricted `vyaparbook_app` role — the same mechanism the catalog tables rely on for their table grants. (The `HasSyncSequenceTraitTest` writes on the restricted connection, so it proves this path.)
2. **A client-`uuid` idempotency convention.** Every syncable table gets a `uuid` column with a `(business_id, uuid)` unique index. Writes go through a "find-by-uuid or create" path so a sale/payment retried over a flaky link posts exactly once (PRD §9). This is data + a service helper, not a trait — the controller must decide the *response* (200 replay vs 201 created).

---

## File Structure

```
backend/
  app/
    Models/
      Customer.php                   (new)
      Sale.php                       (new)
      SaleLine.php                   (new)
      Payment.php                    (new)
    Http/Controllers/Api/V1/
      CustomerController.php         (new — store, update, destroy, restore)
      SaleController.php             (new — store [idempotent], void)
      PaymentController.php          (new — store [idempotent], reverse)
      KhataController.php            (new — index, show)
      SyncController.php             (new — push, pull)
    Services/
      KhataService.php               (new — outstandingFor, ledgerFor, snapshotRate)
      SyncService.php                (new — applyPush, pullSince)
    Policies/
      KhataPolicy.php                (new — recordSale, voidSale, recordPayment)
    Traits/
      HasSyncSequence.php            (new — stamps sync_seq from the global sequence)
  database/
    migrations/
      2026_07_17_000001_create_sync_seq_sequence.php
      2026_07_17_000002_create_customers_table.php
      2026_07_17_000003_create_sales_table.php
      2026_07_17_000004_create_sale_lines_table.php
      2026_07_17_000005_create_payments_table.php
    factories/
      CustomerFactory.php
      SaleFactory.php
      SaleLineFactory.php
      PaymentFactory.php
  routes/api.php                     (modified)
  README.md                          (modified — Khata & Sync API)
  tests/
    Unit/
      HasSyncSequenceTraitTest.php
      KhataServiceTest.php
    Feature/
      Khata/CustomerCrudTest.php
      Khata/SaleTest.php
      Khata/PaymentTest.php
      Khata/KhataReadTest.php
      Khata/KhataRbacTest.php
      Khata/IdempotencyTest.php
      Sync/SyncPushTest.php
      Sync/SyncPullTest.php
      Tenancy/KhataRlsTest.php
      Tenancy/CrossTenantLeakTest.php  (modified — khata cases)
```

**Why `business_id` is in `$fillable` on every model, and `sale_lines` carries its own `business_id`:** same reasons as the catalog slice. Factories fill through `$fillable`, so a non-fillable `business_id` is silently dropped and the insert dies on NOT NULL. And every tenant table — including the child `sale_lines` — carries `business_id` directly so its RLS predicate is flat and needs no join to the parent `sale`. `archived_at`, `version`, `sync_seq`, `created_by`, `total`, `line_total` stay **out** of `$fillable` — they are set by explicit assignment or a trait, never mass-assigned from request input.

---

## Task 1: Global sync sequence + HasSyncSequence trait

**Files:**
- Create: `backend/database/migrations/2026_07_17_000001_create_sync_seq_sequence.php`
- Create: `backend/app/Traits/HasSyncSequence.php`
- Test: `backend/tests/Unit/HasSyncSequenceTraitTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_07_17_000001_create_sync_seq_sequence.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One global sequence, not one per tenant: it only has to be monotonic,
        // never gap-free, and RLS narrows every pull to a single tenant's rows.
        // No explicit GRANT: create_app_role's ALTER DEFAULT PRIVILEGES ... ON
        // SEQUENCES already makes this usable by the restricted role (same as the
        // catalog tables' grants). IF NOT EXISTS because migrate:fresh (db:wipe)
        // drops tables but not a standalone sequence, so a re-run must be a no-op.
        DB::connection('pgsql_migrate')->statement('CREATE SEQUENCE IF NOT EXISTS sync_seq_global');
    }

    public function down(): void
    {
        DB::connection('pgsql_migrate')->statement('DROP SEQUENCE IF EXISTS sync_seq_global');
    }
};
```

- [ ] **Step 2: Write the trait**

```php
<?php
// app/Traits/HasSyncSequence.php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Stamps a globally monotonic `sync_seq` on every insert and update, drawn from
 * the `sync_seq_global` sequence. Delta pull orders by this column and resumes
 * from the max value it last returned.
 *
 * Separate from HasVersion deliberately: `version` is a per-row counter for
 * conflict detection (starts at 1 each row); `sync_seq` is a cross-row cursor.
 * They answer different questions and neither can be derived from the other.
 */
trait HasSyncSequence
{
    public static function bootHasSyncSequence(): void
    {
        static::saving(function ($model) {
            // Draw on the model's own connection so a row written via pgsql_migrate
            // in a test and one written via pgsql in a request both advance the
            // same sequence.
            $next = DB::connection($model->getConnectionName())
                ->selectOne('SELECT nextval(?) AS v', ['sync_seq_global'])->v;

            $model->sync_seq = (int) $next;
        });
    }
}
```

- [ ] **Step 3: Write a failing test against a fixture table**

Mirror `HasVersionTraitTest`'s fixture-table approach (`tests/Unit/HasVersionTraitTest.php`): a throwaway model on `pgsql_migrate` with a `sync_seq` column, asserting (a) an insert stamps a positive value, (b) a second insert stamps a strictly greater value, (c) an update advances it again.

- [ ] **Step 4: Run** — `php artisan test --filter=HasSyncSequenceTraitTest` → PASS (3 passed).

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_07_17_000001_create_sync_seq_sequence.php \
        backend/app/Traits/HasSyncSequence.php \
        backend/tests/Unit/HasSyncSequenceTraitTest.php
git commit -m "feat: add global sync_seq sequence and HasSyncSequence trait"
```

---

## Task 2: Customer model, migration, factory

**Files:**
- Create: `backend/database/migrations/2026_07_17_000002_create_customers_table.php`
- Create: `backend/app/Models/Customer.php`
- Create: `backend/database/factories/CustomerFactory.php`
- Test: `backend/tests/Unit/` coverage folded into `CustomerCrudTest` (Task 7); this task ships the model the rest depends on.

- [ ] **Step 1: Write the migration** (RLS policy identical in shape to `products`)

```php
Schema::connection('pgsql_migrate')->create('customers', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
    $table->uuid('uuid'); // client-generated; idempotency key for offline create
    $table->string('name', 120);
    $table->string('village', 80)->nullable();
    $table->string('phone', 20)->nullable();
    // The khata's starting point: what the customer owed before VyaparBook. Money
    // is decimal(12,2) throughout khata (a distributor's running total dwarfs a
    // single catalog price, so 10,2 from the catalog is widened to 12,2 here).
    $table->decimal('opening_balance', 12, 2)->default(0);
    $table->timestamp('archived_at')->nullable();
    $table->unsignedInteger('version')->default(1);
    $table->bigInteger('sync_seq');
    $table->timestamps();

    $table->unique(['business_id', 'uuid']); // also the business_id index (leftmost)
    $table->index(['business_id', 'sync_seq']); // delta pull scans this
});
// ENABLE + FORCE ROW LEVEL SECURITY, then CREATE POLICY customers_isolation
// USING/WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
```

- [ ] **Step 2: Write the model** — `use BelongsToTenant, HasFactory, HasUuids, HasVersion, HasSyncSequence;`
  `$fillable = ['business_id', 'uuid', 'name', 'village', 'phone', 'opening_balance'];`
  `$casts = ['opening_balance' => 'decimal:2', 'archived_at' => 'datetime', 'version' => 'integer', 'sync_seq' => 'integer'];`
  Relations: `sales(): HasMany`, `payments(): HasMany`.

- [ ] **Step 3: Write the factory** — `business_id => Business::factory()`, `uuid => $this->faker->uuid()`, name/village/phone faker, `opening_balance => '0.00'`.

- [ ] **Step 4: Migrate + a smoke test** (`php artisan migrate --database=pgsql_migrate`; assert a factory row round-trips `opening_balance` as `'0.00'` and gets a positive `sync_seq`). → PASS.

- [ ] **Step 5: Commit** — `feat: add Customer model with RLS isolation policy`.

---

## Task 3: Sale + SaleLine models, migrations, factories

**Files:**
- Create migrations `..._000003_create_sales_table.php`, `..._000004_create_sale_lines_table.php`
- Create: `backend/app/Models/Sale.php`, `backend/app/Models/SaleLine.php`
- Create: `backend/database/factories/SaleFactory.php`, `SaleLineFactory.php`
- Test: `backend/tests/Unit/SaleModelTest.php`

- [ ] **Step 1: `sales` migration**

```php
$table->uuid('id')->primary();
$table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
$table->uuid('uuid');
$table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
$table->date('sale_date');
$table->foreignUuid('created_by')->constrained('users'); // app('tenant.user_id')
// Denormalized sum of line_totals. Stored, not computed on read: the ledger sums
// thousands of sales per customer and must not re-join sale_lines to do it. The
// service asserts it equals Σ line_total at write time (Task 5).
$table->decimal('total', 12, 2);
// Append-only void: a reversal is a NEW sale row pointing back at the original.
// Null on an original; set on its reversal. The original is never mutated.
$table->foreignUuid('reverses_id')->nullable()->constrained('sales');
$table->unsignedInteger('version')->default(1);
$table->bigInteger('sync_seq');
$table->timestamps();

$table->unique(['business_id', 'uuid']);
$table->index(['business_id', 'customer_id']);
$table->index(['business_id', 'sync_seq']);
// + RLS policy sales_isolation (flat, same shape as products)
```

- [ ] **Step 2: `sale_lines` migration**

```php
$table->uuid('id')->primary();
$table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
$table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
$table->foreignUuid('product_pack_id')->constrained('product_packs'); // restrict: catalog archives, never deletes
$table->integer('qty'); // negative qty = a return line (PRD §7 "sales / returns")
// Price SNAPSHOT — copied from the product_pack at sale time, never read live.
$table->decimal('rate', 10, 2);
$table->decimal('line_total', 12, 2); // rate * qty, stored immutable
$table->unsignedInteger('version')->default(1);
$table->bigInteger('sync_seq');
$table->timestamps();

$table->index(['business_id', 'sale_id']);
$table->index(['business_id', 'sync_seq']);
// + RLS policy sale_lines_isolation (flat; business_id is carried directly, no join)
```

`sale_lines` has **no `uuid`**: a line is never independently created offline — it is only ever written as part of its parent `Sale` in one transaction, so the sale's `(business_id, uuid)` idempotency already covers it. It still carries `sync_seq` so pull streams lines alongside their sale.

- [ ] **Step 3: Models** — both `use BelongsToTenant, HasFactory, HasUuids, HasVersion, HasSyncSequence;`.
  `Sale`: fillable `['business_id','uuid','customer_id','sale_date','reverses_id']` (**not** `total`, `created_by` — set explicitly); casts `sale_date` → date, `total` → decimal:2. Relations: `customer()`, `lines(): HasMany`, `reverses(): BelongsTo self`.
  `SaleLine`: fillable `['business_id','sale_id','product_pack_id','qty','rate']` (**not** `line_total`); casts `rate`/`line_total` → decimal:2, `qty` → integer. Relations: `sale()`, `productPack()`.

- [ ] **Step 4: Factories** — `SaleFactory` builds an unrelated business/customer/user by default (callers pass the trio when they must agree, exactly like `ProductPackFactory`). `total => '0.00'` default. `SaleLineFactory` sets `rate`, `qty`, and `line_total = bcmul(rate, qty, 2)`.

- [ ] **Step 5: Failing test** (`SaleModelTest`): a sale with two lines relates them; `reverses()` resolves a reversal back to its original; `sale_lines.rate` persists as a snapshot independent of the pack's later price. → PASS after migrate.

- [ ] **Step 6: Commit** — `feat: add Sale and SaleLine models with append-only RLS ledger`.

---

## Task 4: Payment model, migration, factory

**Files:**
- Create migration `..._000005_create_payments_table.php`
- Create: `backend/app/Models/Payment.php`, `backend/database/factories/PaymentFactory.php`
- Test: `backend/tests/Unit/PaymentModelTest.php`

- [ ] **Step 1: `payments` migration**

```php
$table->uuid('id')->primary();
$table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
$table->uuid('uuid');
$table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
$table->date('payment_date');
$table->decimal('amount', 12, 2); // positive = customer paid; a reversal negates
$table->string('mode', 20); // cash | upi | cheque | bank | other (validated in controller)
$table->foreignUuid('created_by')->constrained('users');
$table->foreignUuid('reverses_id')->nullable()->constrained('payments');
$table->unsignedInteger('version')->default(1);
$table->bigInteger('sync_seq');
$table->timestamps();

$table->unique(['business_id', 'uuid']);
$table->index(['business_id', 'customer_id']);
$table->index(['business_id', 'sync_seq']);
// + RLS policy payments_isolation (flat)
```

- [ ] **Step 2: Model** — `use BelongsToTenant, HasFactory, HasUuids, HasVersion, HasSyncSequence;`
  fillable `['business_id','uuid','customer_id','payment_date','amount','mode','reverses_id']` (**not** `created_by`); casts `amount` → decimal:2, `payment_date` → date. Relations `customer()`, `reverses()`.

- [ ] **Step 3: Factory** — unrelated defaults, `mode => 'cash'`, `amount` faker as a 2-decimal string.

- [ ] **Step 4: Failing test** — amount round-trips as a string; a reversal resolves its original. → PASS.

- [ ] **Step 5: Commit** — `feat: add Payment model with RLS isolation policy`.

---

## Task 5: KhataService — outstanding, ledger, snapshot rate

**Files:**
- Create: `backend/app/Services/KhataService.php`
- Test: `backend/tests/Unit/KhataServiceTest.php`

This is the correctness core. All three methods use exact decimal math; none returns a float.

- [ ] **Step 1: Failing test** — cover:
  - `outstandingFor`: `opening_balance + Σsale.total − Σpayment.amount`, as an exact string. A reversal (negative-net sale/payment) leaves the total unchanged.
  - `outstandingFor` with no activity returns the opening balance verbatim.
  - `ledgerFor`: returns sales and payments merged, ordered by date then `created_at`, each annotated with a **running balance** whose final value equals `outstandingFor`.
  - `snapshotRate`: given a `ProductPack`, returns its `default_sell_price` as the rate to freeze onto a line (kept in the service so "where the sale rate comes from" has one home).

- [ ] **Step 2: Write the service**

```php
<?php
// app/Services/KhataService.php

namespace App\Services;

use App\Models\Customer;
use App\Models\ProductPack;
use Illuminate\Support\Collection;

class KhataService
{
    /**
     * outstanding = opening_balance + Σ sale.total − Σ payment.amount.
     *
     * Reversals are ordinary rows with negated amounts, so they self-cancel here
     * and no row is ever excluded or mutated — "outstanding is always
     * recomputable" (PRD §9). bcadd/bcsub at scale 2: rupees must not drift.
     */
    public function outstandingFor(Customer $customer): string
    {
        $sales = (string) $customer->sales()->sum('total');       // DB SUM, exact on numeric
        $paid  = (string) $customer->payments()->sum('amount');

        $balance = bcadd((string) $customer->opening_balance, $sales, 2);

        return bcsub($balance, $paid, 2);
    }

    /**
     * A time-ordered khata statement: every sale and payment for the customer as
     * one stream with a running balance. Debits (sales) raise it, credits
     * (payments) lower it; the last running value equals outstandingFor().
     */
    public function ledgerFor(Customer $customer): Collection
    {
        $entries = $customer->sales()->get()
            ->map(fn ($s) => [
                'kind' => $s->reverses_id ? 'sale_reversal' : 'sale',
                'ref' => $s,
                'date' => $s->sale_date,
                'delta' => (string) $s->total,          // debit: +
            ])
            ->concat($customer->payments()->get()->map(fn ($p) => [
                'kind' => $p->reverses_id ? 'payment_reversal' : 'payment',
                'ref' => $p,
                'date' => $p->payment_date,
                'delta' => bcmul((string) $p->amount, '-1', 2), // credit: −
            ]))
            ->sortBy([['date', 'asc'], fn ($e) => $e['ref']->created_at])
            ->values();

        $running = (string) $customer->opening_balance;

        return $entries->map(function ($e) use (&$running) {
            $running = bcadd($running, $e['delta'], 2);
            $e['running_balance'] = $running;

            return $e;
        });
    }

    /** The rate to freeze onto a new sale line — one home for that decision. */
    public function snapshotRate(ProductPack $pack): string
    {
        return (string) $pack->default_sell_price;
    }
}
```

- [ ] **Step 3: Run** — `php artisan test --filter=KhataServiceTest` → PASS.

- [ ] **Step 4: Commit** — `feat: add KhataService with exact-decimal outstanding and ledger`.

> **Note for the implementer:** `->sum()` on a Postgres `numeric` column returns an exact value; cast to string before bc math. Verify in tinker that Laravel does not hand back a float for these columns on this driver — if it does, sum via `selectRaw('sum(total)::text')` instead. Redis caching of outstanding (PRD §10) is deliberately not built here; when it lands it wraps `outstandingFor`, keyed per tenant+customer, invalidated on any sale/payment write.

---

## Task 6: KhataPolicy

**Files:**
- Create: `backend/app/Policies/KhataPolicy.php`
- Test: covered by Task 13's RBAC test (this task ships the class controllers call)

Mirrors `CatalogPolicy` — reads the verified role from `app('tenant.role')`, never the client. Encodes the PRD §7 matrix exactly:

```php
<?php
// app/Policies/KhataPolicy.php

namespace App\Policies;

class KhataPolicy
{
    /** PRD §7 "Create sales / returns": owner, admin, salesman (not accountant). */
    public function recordSale(): bool
    {
        return in_array(app('tenant.role'), ['owner', 'admin', 'salesman'], true);
    }

    /** PRD §7 "Edit/void sales": owner, admin only. */
    public function voidSale(): bool
    {
        return in_array(app('tenant.role'), ['owner', 'admin'], true);
    }

    /** PRD §7 "Record payments": all four roles. */
    public function recordPayment(): bool
    {
        return app('tenant.role') !== null;
    }

    // "View customer khata" and customer CRUD reuse recordSale-level gating for
    // writes; reads are open to any member (a salesman and an accountant both
    // need the khata). Customer create/edit follows recordSale.
}
```

- [ ] **Step 1: Write the policy.**  **Step 2: Commit** — `feat: add KhataPolicy encoding the PRD §7 role matrix`.

---

## Task 7: Customer CRUD + archive/restore

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/CustomerController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Khata/CustomerCrudTest.php`

Follows `ProductController` exactly: `(new KhataPolicy())->recordSale()` gates writes (a salesman onboards customers); `findOrFail` for tenant-scoped 404-not-403; archive sets `archived_at`, never deletes. `uuid` is accepted from the client when present (offline create) and generated server-side otherwise, via a find-or-create on `(business_id, uuid)` for idempotency. Validation whitelist: `name` (required), `village`/`phone` (nullable), `opening_balance` (nullable numeric ≥ 0), `uuid` (nullable uuid). `business_id`, `created_by`, `sync_seq` never come from the request.

- [ ] Steps: controller → routes (`customers` resource under the `require.tenant` group) → failing feature test (create stamps tenant; salesman allowed; accountant 403; cross-tenant 404; duplicate `uuid` replays the same row) → run → commit `feat: add customer CRUD with idempotent create`.

---

## Task 8: Sale create (idempotent) + void

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/SaleController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Khata/SaleTest.php`

- [ ] **Step 1: `store` — idempotent, append-only, price-snapshotting.**

```php
public function store(Request $request, KhataService $khata)
{
    if (! (new KhataPolicy())->recordSale()) {
        return $this->denied();
    }

    $data = $request->validate([
        'uuid' => ['required', 'uuid'], // client-generated: the idempotency key
        'customer_id' => ['required', 'uuid'],
        'sale_date' => ['required', 'date'],
        'lines' => ['required', 'array', 'min:1'],
        'lines.*.product_pack_id' => ['required', 'uuid'],
        'lines.*.qty' => ['required', 'integer', 'not_in:0'], // negative = return
    ]);

    // Idempotent replay: a retry over a flaky link must not double-post. RLS has
    // already scoped `sales` to this tenant, so a uuid match is this tenant's row.
    $existing = Sale::where('uuid', $data['uuid'])->first();
    if ($existing) {
        return response()->json($existing->load('lines'), 200);
    }

    // findOrFail runs under RLS: a customer/pack from another tenant is invisible,
    // so a cross-tenant id 404s rather than leaking existence.
    $customer = Customer::findOrFail($data['customer_id']);

    $sale = DB::transaction(function () use ($data, $customer, $khata) {
        $sale = new Sale([
            'business_id' => app('tenant.id'),
            'uuid' => $data['uuid'],
            'customer_id' => $customer->id,
            'sale_date' => $data['sale_date'],
        ]);
        $sale->created_by = app('tenant.user_id'); // not fillable
        $sale->total = '0.00';
        $sale->save();

        $total = '0.00';
        foreach ($data['lines'] as $line) {
            $pack = ProductPack::findOrFail($line['product_pack_id']);
            $rate = $khata->snapshotRate($pack); // frozen now, never read live
            $lineTotal = bcmul($rate, (string) $line['qty'], 2);

            $saleLine = new SaleLine([
                'business_id' => app('tenant.id'),
                'sale_id' => $sale->id,
                'product_pack_id' => $pack->id,
                'qty' => $line['qty'],
                'rate' => $rate,
            ]);
            $saleLine->line_total = $lineTotal; // not fillable
            $saleLine->save();

            $total = bcadd($total, $lineTotal, 2);
        }

        $sale->total = $total; // total = Σ line_total, asserted by construction
        $sale->save();

        return $sale;
    });

    return response()->json($sale->load('lines'), 201);
}
```

- [ ] **Step 2: `void` — append-only reversal (owner/admin).**

```php
public function void(string $id)
{
    if (! (new KhataPolicy())->voidSale()) {
        return $this->denied();
    }

    $original = Sale::with('lines')->findOrFail($id);

    if ($original->reverses_id) {
        return response()->json(['message' => 'Cannot void a reversal.'], 422);
    }
    if (Sale::where('reverses_id', $original->id)->exists()) {
        return response()->json(['message' => 'Sale is already voided.'], 409);
    }

    // A void writes a NEW sale with negated lines and total, pointing back at the
    // original. The original stays byte-for-byte intact; outstanding nets to the
    // pre-sale value because the reversal's total cancels it.
    $reversal = DB::transaction(function () use ($original) {
        $reversal = new Sale([
            'business_id' => app('tenant.id'),
            'uuid' => (string) Str::uuid(),  // server-generated: a void has no client uuid
            'customer_id' => $original->customer_id,
            'sale_date' => now()->toDateString(),
            'reverses_id' => $original->id,
        ]);
        $reversal->created_by = app('tenant.user_id');
        $reversal->total = bcmul((string) $original->total, '-1', 2);
        $reversal->save();

        foreach ($original->lines as $line) {
            $r = new SaleLine([
                'business_id' => app('tenant.id'),
                'sale_id' => $reversal->id,
                'product_pack_id' => $line->product_pack_id,
                'qty' => -$line->qty,
                'rate' => $line->rate, // same frozen rate, negated qty
            ]);
            $r->line_total = bcmul((string) $line->line_total, '-1', 2);
            $r->save();
        }

        return $reversal;
    });

    return response()->json($reversal->load('lines'), 201);
}
```

- [ ] **Step 3: Routes** — `POST sales`, `POST sales/{id}/void` under `require.tenant`.

- [ ] **Step 4: Failing feature test** (`SaleTest`): create stamps tenant + `created_by` + correct `total`; the same `uuid` posted twice yields one sale (200 on replay); a return line (negative qty) lowers the total; `void` creates a reversal and leaves outstanding at the pre-sale figure; a salesman can create but **cannot** void (403); double-void 409s. → PASS.

- [ ] **Step 5: Commit** — `feat: add idempotent sale create and append-only void`.

---

## Task 9: Payment record (idempotent) + reversal

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/PaymentController.php`; modify routes
- Test: `backend/tests/Feature/Khata/PaymentTest.php`

Same shape as Task 8 but simpler (no lines). `store` gates on `recordPayment()` (all four roles), validates `uuid`/`customer_id`/`payment_date`/`amount` (`numeric`, `gt:0`)/`mode` (`Rule::in(['cash','upi','cheque','bank','other'])`), replays on duplicate `uuid`, stamps `created_by`. `reverse` gates on `voidSale()` (a payment reversal is a correction — owner/admin), writes a new payment with `amount = −original` and `reverses_id` set; 409 on double-reverse.

- [ ] Steps: controller → routes → failing test (record lowers outstanding; replay idempotent; accountant may record but not reverse; reversal restores outstanding) → run → commit `feat: add idempotent payment record and reversal`.

---

## Task 10: Khata read — summary + per-customer ledger

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/KhataController.php`; modify routes
- Test: `backend/tests/Feature/Khata/KhataReadTest.php`

Reads are open to **any** member (salesman and accountant both need the khata).

- [ ] **`index`** — `GET /khata`: every non-archived customer with its `outstanding` (from `KhataService`), for the "who owes me" screen. `?include_archived=1` for the full view, mirroring `GET /catalog`.
- [ ] **`show`** — `GET /khata/{customer}`: the customer plus its `ledgerFor` statement (time-ordered entries with running balance) and final `outstanding`. `findOrFail` → cross-tenant 404.
- [ ] Failing test: outstanding matches `opening + sales − payments`; ledger entries are date-ordered with a running balance whose last value equals outstanding; a reversal shows as its own entry and nets out; cross-tenant customer 404s. → PASS. Commit `feat: add khata summary and per-customer ledger reads`.

---

## Task 11: Sync push

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/SyncController.php`, `backend/app/Services/SyncService.php`; modify routes
- Test: `backend/tests/Feature/Sync/SyncPushTest.php`

- [ ] **`POST /sync/push`** — a batch the client's outbox drains. Body: `{ mutations: [ { type, tenant_id, uuid, payload } ] }`. For each mutation `SyncService::applyPush`:
  1. **Reject tenant mismatch.** If `tenant_id !== app('tenant.id')` → record `rejected` for that item and skip it. This is belt-and-suspenders with RLS `WITH CHECK`; the test proves the app layer rejects *before* the DB would (PRD §9).
  2. **Idempotent apply** by `(business_id, uuid)` — reuse the Task 8/9 create paths so a mutation already applied returns `duplicate`, a new one returns `applied` with the server row.
  3. Everything runs inside the request transaction `SetTenantContext` opened; a single bad item is reported, not fatal to the batch (collect per-item results).
  Response: `{ results: [ { uuid, status: applied|duplicate|rejected, id? } ] }`, `200`.

- [ ] Failing test: a fresh mutation applies and returns the server id; the same mutation in a second push is `duplicate` and creates nothing; a mutation carrying another tenant's `tenant_id` is `rejected` and writes nothing (assert via `pgsql_migrate` count); role gating still applies (a salesman's payment-reversal mutation is rejected by policy). → PASS. Commit `feat: add idempotent sync push with tenant-mismatch rejection`.

---

## Task 12: Sync pull

**Files:**
- Modify: `SyncController`, `SyncService`; modify routes
- Test: `backend/tests/Feature/Sync/SyncPullTest.php`

- [ ] **`GET /sync/pull?since=<seq>`** — `SyncService::pullSince` returns, per table, rows with `sync_seq > since` ordered by `sync_seq`, plus the new `cursor` (max `sync_seq` returned across all tables, or `since` when nothing changed). RLS guarantees only the caller's tenant appears. Shape: `{ cursor, customers: [...], sales: [...], sale_lines: [...], payments: [...] }`. A default `since=0` pulls everything (initial hydrate).

- [ ] Failing test:
  - initial pull (`since=0`) returns all the tenant's rows and a `cursor` > 0;
  - after one new payment, pull from the previous cursor returns **only** that payment and its sale-free delta;
  - a neighbour tenant's rows never appear regardless of `since`;
  - `cursor` is stable when nothing changed (pull twice → empty second delta).
  → PASS. Commit `feat: add delta sync pull keyed on sync_seq`.

---

## Task 13: RBAC coverage across roles

**Files:**
- Test: `backend/tests/Feature/Khata/KhataRbacTest.php`

- [ ] One table-driven test per capability against all four roles, asserting the PRD §7 matrix exactly:
  - create sale: owner ✓, admin ✓, salesman ✓, accountant ✗ (403)
  - void sale: owner ✓, admin ✓, salesman ✗, accountant ✗
  - record payment: all four ✓
  - reverse payment: owner ✓, admin ✓, salesman ✗, accountant ✗
  - view khata: all four ✓
  → PASS. Commit `test: cover khata RBAC across all four roles`.

---

## Task 14: DB-level RLS proof for khata tables

**Files:**
- Test: `backend/tests/Feature/Tenancy/KhataRlsTest.php`

- [ ] Mirror `CatalogRlsTest` — query builder, no Eloquent, app layer removed:
  - a neighbour's `customers`/`sales`/`payments` are invisible under `switchTo(mine)`;
  - inserting any of the four with a mismatched `business_id` throws (WITH CHECK);
  - a tenant sees its own rows.
  → PASS. Commit `test: prove khata RLS policies with the app layer bypassed`.

---

## Task 15: Khata cases in the cross-tenant leak suite

**Files:**
- Modify: `backend/tests/Feature/Tenancy/CrossTenantLeakTest.php`

- [ ] Append (into the existing suite, per its convention):
  - business A's `GET /khata` never shows B's customers;
  - A's owner posting a sale with B's `customer_id` → 404 (RLS hid the customer);
  - A's owner voiding B's sale → 404, and B's sale is provably untouched (read back with `withoutGlobalScopes()` on `pgsql_migrate` — the request pins `app('tenant.id')` to A, exactly the trap Task 17 of the catalog slice hit);
  - a `sync/push` mutation stamped with B's `tenant_id` from A's session is rejected and writes nothing.
  → PASS. Commit `test: extend cross-tenant leak suite with khata cases`.

---

## Task 16: Full suite, docs, plan close-out

**Files:**
- Modify: `backend/README.md`, this plan.

- [ ] **Step 1** — `php artisan test`: full suite green. Baseline entering this slice is **102** (end of the catalog slice); every task above only adds tests. A materially lower number means tasks were skipped.
- [ ] **Step 2** — append a "Khata & Sync API" section to `backend/README.md`: the route/role table (from PRD §7), the append-only/reversal rule, the price-snapshot rule, idempotency by `(business_id, uuid)`, and the `sync_seq` cursor contract. Call out the two look-like-bugs-but-arent behaviours: cross-tenant 404-not-403, and `sync/push` rejecting mismatched `tenant_id` at the app layer *before* RLS would.
- [ ] **Step 3** — tick every checkbox in this plan and add a **Known Gaps** section (Redis-cached outstanding not built; plan-limit gating from PRD §8 deferred; no frontend outbox — the server contract is proven by `SyncPush/PullTest`, not by a real Dexie client). If nothing else was deferred, say so.
- [ ] **Step 4** — Commit `docs: document the khata & sync API and close out the plan`.

---

## Self-Review Notes

**PRD coverage** — every khata-touching PRD section maps to a task:

| PRD section | Tasks |
|---|---|
| §7 RBAC matrix (sales/void/payment/view) | 6, 8, 9, 10, 13 |
| §9 append-only ledger, outstanding recomputable | 3, 4, 5, 8, 9 |
| §9 idempotency `(tenant, uuid)`, retry-exactly-once | 2–4, 8, 9, 11 |
| §9 sync push (tenant-mismatch rejection) | 11 |
| §9 sync pull (delta cursor) | 1, 12 |
| §10 `Customer`/`Sale`/`SaleLine`/`Payment` | 2, 3, 4 |
| §10 outstanding by aggregation | 5, 10 |
| §11 tenant-scoped endpoints, no `tenant_id` in path | all controllers |
| isolation (RLS + BelongsToTenant + defense in depth) | 2–4, 14, 15 |

**Deliberate design decisions, called out rather than silent:**

- **No `ledger_entries` table.** Sales and payments *are* the immutable entries; the ledger is their time-ordered merge and outstanding is their aggregate. A separate entries table would duplicate every amount (drift risk) for no gain, and contradicts "always recomputable."
- **Append-only voids, not a status flag.** PRD §9 says corrections are new voiding entries. Flipping `status='void'` on the original would mutate history and break offline replay; a reversal row preserves both.
- **Price snapshot on `sale_lines.rate`.** The single most common khata bug is re-pricing old sales when the catalog changes. Freezing the rate at write time is non-negotiable; `sale_lines` has no FK-driven price read.
- **`sync_seq` global sequence, not per-tenant and not `updated_at`.** `updated_at` collides at sub-second precision and isn't a safe resume cursor; a per-tenant sequence buys nothing because RLS already scopes the pull. Monotonic-but-gappy is all a cursor needs.
- **Outstanding computed on read.** PRD §10's Redis cache is an optimization with an invalidation cost; correctness comes first and the cache wraps `outstandingFor` later without changing its contract.

**Additions beyond the strict PRD, and why:** Task 1's `sync_seq` machinery is infrastructure the PRD implies ("delta since a per-tenant cursor") but does not specify; it is built once here and generalizes to stock/production later. `HasSyncSequence` is kept separate from `HasVersion` because a cross-row cursor and a per-row conflict counter are different concerns.

**Known risk the plan cannot remove:** as with every slice so far, the suite runs against Postgres directly — PgBouncer is not configured. The `SET LOCAL` tenant GUC and RLS guarantees are proven against Postgres semantics, not transaction pooling in situ. Unchanged by this slice; a green suite is not evidence pooling is safe.

**Test count target:** 102 (baseline) + roughly 3+1+1+1+4+5+3+3+5+4+4+5+5+4 across Tasks 1–15 → **~150 passing**. A materially lower number means tasks were skipped.
```
