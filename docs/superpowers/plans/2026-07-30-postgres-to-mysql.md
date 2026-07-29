# PostgreSQL → MySQL Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move VyaparBook from PostgreSQL to MySQL 8, replacing 23 row-level-security policies (covering 27 tables) with a fail-closed application scope plus a test-environment query tripwire.

**Architecture:** One `mysql` connection replaces the `pgsql`/`pgsql_migrate` two-role split (that split existed only so the app role could not bypass RLS). `BelongsToTenant` inverts from fail-open to fail-closed and throws when no tenant is bound; four legitimate cross-tenant paths use an explicit `Tenancy::withoutTenant()`. The `sync_seq_global` sequence becomes a per-tenant counter row. No data migrates — the only real tenant reseeds from `database/seed_data/`.

**Tech Stack:** PHP 8.3, Laravel 11.54, MySQL 8, Pest, bcmath.

**Spec:** `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`
**Branch:** `feat/mysql-migration` (step 1, database config, already committed as `a893f68`)

---

## Read this before Task 1

**The branch is red until Task 4.** Tasks 2–3 cannot run the test suite because the test harness itself still points at `pgsql_migrate`. Do not treat a failing suite before Task 4 as a mistake.

**Eloquent event order matters in Task 3.** Laravel fires `saving` **before** `creating`. `HasSyncSequence` hooks `saving`; `BelongsToTenant` sets `business_id` on `creating`. So at the moment the sequence is drawn, a brand-new model's `business_id` is still null. Task 3's code resolves `$model->business_id ?? app('tenant.id')` for exactly this reason. Removing that fallback will break every insert.

**Verified counts at the time of writing** (re-measure if the branch has moved):

| Thing | Count |
|---|---|
| Files naming `pgsql` outside `config/` | 164 |
| Migrations calling `Schema::connection('pgsql_migrate')` | 50 of 53 |
| `ENABLE ROW LEVEL SECURITY` / `CREATE POLICY` statements | 23 each |
| Tables those statements actually cover | **27** (some statements loop over a pair) |
| Models using `HasSyncSequence` (all tenant-owned) | 12 |
| `selectRaw` blocks | 35 |
| Decimal columns | 41 |
| `app/` files with RLS comments | 43 |
| Tests in `tests/Feature/Tenancy/` | 40 |

---

## File Structure

**Created:**
- `app/Support/Tenancy.php` — the only sanctioned cross-tenant escape hatch
- `app/Exceptions/TenantContextMissing.php` — thrown by the fail-closed scope
- `app/Providers/QueryTripwireServiceProvider.php` — test-environment leak detector
- `database/migrations/2026_07_30_000001_create_sync_sequences_table.php`
- `tests/Feature/Tenancy/FailClosedScopeTest.php`
- `tests/Feature/Database/DecimalFidelityTest.php`

**Modified:**
- `app/Traits/BelongsToTenant.php` — fail-open → fail-closed
- `app/Traits/HasSyncSequence.php` — sequence → per-tenant counter
- `app/Support/TenantContext.php` — drop both `set_config` calls
- `app/Http/Middleware/SetTenantContext.php:56,72` — drop both `set_config` calls
- `tests/RefreshesTenantDatabase.php` — single connection
- 50 migrations, ~105 test files, 9 `pgsql_platform` call sites, 35 `selectRaw` blocks

**Deleted:**
- `database/migrations/2026_07_17_000001_create_sync_seq_sequence.php`
- `tests/Feature/Tenancy/{Billing,Catalog,Khata,Membership,Stock}RlsTest.php`
- `tests/Feature/Tenancy/PgBouncerPooledConnectionTest.php`

---

### Task 1: Environment prerequisites

**This task is run by the user — it needs sudo.** Nothing else in the plan can proceed until it passes.

**Files:** none.

- [ ] **Step 1: Install the MySQL PDO driver and start the server**

The machine currently has `pdo_pgsql` only, so no MySQL connection is possible at all.

```bash
sudo apt install php8.3-mysql
sudo service mysql start
```

If `php8.3-mysql` is not in apt (this box only offers `php8.1-mysql`), add the sury repo first:

```bash
sudo apt install -y lsb-release ca-certificates curl
sudo curl -sSLo /etc/apt/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
echo "deb [signed-by=/etc/apt/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/sury-php.list
sudo apt update && sudo apt install php8.3-mysql
```

- [ ] **Step 2: Verify the driver is loaded**

Run: `php -r "var_export(PDO::getAvailableDrivers());"`
Expected: an array containing `'mysql'`. If it shows only `'pgsql'`, stop — nothing later will work.

- [ ] **Step 3: Create the database and both users**

```bash
sudo mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS vyaparbook
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- BOTH host patterns. MySQL resolves 127.0.0.1 back to `localhost`, so a
-- grant to '%' alone is NOT matched and you get a confusing
-- "Access denied for user ...@'localhost'" even though DB_HOST is 127.0.0.1.
CREATE USER IF NOT EXISTS 'vyaparbook_app'@'localhost' IDENTIFIED BY 'vyaparbook_app_secret';
CREATE USER IF NOT EXISTS 'vyaparbook_app'@'%' IDENTIFIED BY 'vyaparbook_app_secret';
GRANT ALL PRIVILEGES ON vyaparbook.* TO 'vyaparbook_app'@'localhost';
GRANT ALL PRIVILEGES ON vyaparbook.* TO 'vyaparbook_app'@'%';

-- SELECT and nothing else: this is the half of the old BYPASSRLS role that
-- survives, so the superadmin console physically cannot mutate tenant data.
CREATE USER IF NOT EXISTS 'vyapar_platform_ro'@'localhost' IDENTIFIED BY 'platform_ro_pw';
CREATE USER IF NOT EXISTS 'vyapar_platform_ro'@'%' IDENTIFIED BY 'platform_ro_pw';
GRANT SELECT ON vyaparbook.* TO 'vyapar_platform_ro'@'localhost';
GRANT SELECT ON vyaparbook.* TO 'vyapar_platform_ro'@'%';

FLUSH PRIVILEGES;
SQL
```

- [ ] **Step 4: Verify the app can connect and that decimals arrive as strings**

Run:
```bash
cd backend && php artisan tinker --execute="
\$r = DB::select('SELECT CAST(1234.56 AS DECIMAL(12,2)) AS d')[0];
echo 'connected: ', DB::connection()->getDatabaseName(), PHP_EOL;
echo 'decimal php type: ', gettype(\$r->d), PHP_EOL;"
```
Expected:
```
connected: vyaparbook
decimal php type: string
```

If it prints `double`, `PDO::ATTR_EMULATE_PREPARES` is not taking effect. **Stop and fix it** — every rupee in this system is a decimal string through bcmath, and floats will silently corrupt khatas. Task 9 pins this with a test.

- [ ] **Step 5: Confirm the read-only user really is read-only**

Run: `mysql -u vyapar_platform_ro -pplatform_ro_pw vyaparbook -e "CREATE TABLE t(x int);"`
Expected: `ERROR 1142 (42000): CREATE command denied`

---

### Task 2: Strip Postgres from the 53 migrations

**Files:**
- Modify: all of `database/migrations/*.php` (50 reference `pgsql_migrate`)
- Delete: `database/migrations/2026_07_17_000001_create_sync_seq_sequence.php`
- Create: `database/migrations/2026_07_30_000001_create_sync_sequences_table.php`

- [ ] **Step 1: See what one migration looks like before and after**

`database/migrations/2026_07_26_000001_create_orders_tables.php` currently ends with:

```php
        foreach (['orders', 'order_lines'] as $table) {
            DB::connection('pgsql_migrate')->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::connection('pgsql_migrate')->statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::connection('pgsql_migrate')->statement(
                "CREATE POLICY {$table}_isolation ON {$table}
                 USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
                 WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)"
            );
        }
```

That whole `foreach` is **deleted** — not translated. And `Schema::connection('pgsql_migrate')->create(...)` becomes `Schema::create(...)`.

- [ ] **Step 2: Remove the connection pin across all migrations**

```bash
cd backend
sed -i "s/Schema::connection('pgsql_migrate')->/Schema::/g" database/migrations/*.php
sed -i "s/DB::connection('pgsql_migrate')->/DB::/g" database/migrations/*.php
```

- [ ] **Step 3: Verify the pin is gone**

Run: `grep -rc "pgsql_migrate" database/migrations/ | grep -v ':0' || echo CLEAN`
Expected: `CLEAN`

- [ ] **Step 4: Delete every RLS block by hand**

These are multi-line and vary in shape, so do them per file rather than with `sed`. Find them:

Run: `grep -rln "ROW LEVEL SECURITY" database/migrations/`
Expected: 23 files.

In each, delete the statements that mention `ENABLE ROW LEVEL SECURITY`, `FORCE ROW LEVEL SECURITY`, and `CREATE POLICY` — including any `foreach` wrapper that exists only to issue them. Leave every `Schema::create` and column definition untouched.

- [ ] **Step 5: Verify no RLS remains**

Run: `grep -rn "ROW LEVEL SECURITY\|CREATE POLICY\|current_setting" database/migrations/ || echo CLEAN`
Expected: `CLEAN`

- [ ] **Step 6: Replace the sequence with a per-tenant counter table**

```bash
git rm database/migrations/2026_07_17_000001_create_sync_seq_sequence.php
```

Create `database/migrations/2026_07_30_000001_create_sync_sequences_table.php`:

```php
<?php
// database/migrations/2026_07_30_000001_create_sync_sequences_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One monotonic counter per tenant, replacing Postgres's `sync_seq_global`.
 *
 * MySQL has no sequences. The obvious emulation is a single counter row, but
 * that would serialise EVERY write on the platform against one row lock.
 *
 * sync_seq only ever needs to be monotonic WITHIN a tenant: the delta pull is
 * always `business_id = X AND sync_seq > cursor`, and each device holds a
 * per-tenant Dexie database and a per-tenant cursor. A global sequence was
 * stronger than the invariant required, so contention drops to one shop's own
 * writes — which mostly serialise anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_sequences', function (Blueprint $table) {
            // PK, not just an index: one row per tenant is the whole design, and
            // a duplicate row would hand out the same sync_seq twice.
            $table->foreignUuid('business_id')->primary()
                ->constrained('businesses')->cascadeOnDelete();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_sequences');
    }
};
```

- [ ] **Step 7: Build the schema on MySQL**

Run: `php artisan migrate:fresh --force`
Expected: every migration `DONE`, no `--database` flag needed. If it errors on a Postgres idiom, fix that migration and re-run.

- [ ] **Step 8: Confirm UUID keys landed as CHAR(36)**

Spec Decision 4 needs no code — `foreignUuid()` already emits `CHAR(36)` on
MySQL — but verify rather than assume:

Run: `php artisan tinker --execute="print_r(DB::select('DESCRIBE customers')[0]);"`
Expected: the `id` column shows `Type => char(36)`.

- [ ] **Step 9: Commit**

```bash
git add -A database/migrations
git commit -m "refactor: build the schema on MySQL

Drops the pgsql_migrate connection pin from 50 migrations and deletes 23
RLS policy blocks. The two-role split existed only so the app role could
not bypass row-level security; MySQL has no RLS, so it protects nothing.

Replaces sync_seq_global with a per-tenant counter table. A single global
counter would serialise every write on the platform against one row lock,
and sync_seq only needs to be monotonic within a tenant -- the delta pull
is always scoped by business_id."
```

---

### Task 3: Per-tenant sync sequence

**Files:**
- Modify: `app/Traits/HasSyncSequence.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Sync/SyncSequenceTest.php`:

```php
<?php
// tests/Feature/Sync/SyncSequenceTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Support\Tenancy;
use Illuminate\Support\Str;

/** A customer written under an explicit tenant. */
function seqCustomer(Business $b, string $name): Customer
{
    return Customer::create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'opening_balance' => '0.00',
    ]);
}

it('hands out strictly increasing sync_seq within a tenant', function () {
    $b = Business::factory()->create();

    $first = seqCustomer($b, 'One');
    $second = seqCustomer($b, 'Two');

    expect($second->sync_seq)->toBeGreaterThan($first->sync_seq);
});

it('advances on update as well as insert, so a delta pull sees the change', function () {
    $b = Business::factory()->create();
    $c = seqCustomer($b, 'One');
    $afterInsert = $c->sync_seq;

    $c->name = 'One Renamed';
    $c->save();

    expect($c->sync_seq)->toBeGreaterThan($afterInsert);
});

it('counts each tenant independently, so one shop cannot exhaust another', function () {
    // The whole point of per-tenant counters: contention and numbering are
    // scoped to a shop, not shared platform-wide.
    $a = Business::factory()->create();
    $b = Business::factory()->create();

    seqCustomer($a, 'A1');
    seqCustomer($a, 'A2');
    $firstOfB = seqCustomer($b, 'B1');

    expect($firstOfB->sync_seq)->toBe(1);
});

it('creates the counter row on demand rather than needing business creation to seed it', function () {
    // Self-healing: a business created before this table existed, or by a
    // seeder that bypassed a hook, still gets a working counter.
    $b = Business::factory()->create();
    DB::table('sync_sequences')->where('business_id', $b->id)->delete();

    $c = seqCustomer($b, 'One');

    expect($c->sync_seq)->toBe(1);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Sync/SyncSequenceTest.php`
Expected: FAIL — `nextval` does not exist in MySQL.

- [ ] **Step 3: Rewrite the trait**

Replace `app/Traits/HasSyncSequence.php` entirely:

```php
<?php
// app/Traits/HasSyncSequence.php

namespace App\Traits;

use RuntimeException;
use Illuminate\Support\Facades\DB;

/**
 * Stamps a monotonic `sync_seq` on every insert and update. Delta pull orders
 * rows by this column and resumes from the max value it last returned.
 *
 * Postgres drew this from a lock-free global sequence. MySQL has none, so it
 * comes from a per-tenant counter row (see the sync_sequences migration): a
 * single global counter would serialise every write on the platform, and
 * sync_seq only needs to be monotonic WITHIN a tenant because the delta pull is
 * always scoped by business_id.
 *
 * Kept separate from HasVersion deliberately: `version` is a per-row counter for
 * conflict detection, while `sync_seq` is a cross-row cursor. They answer
 * different questions and neither derives from the other.
 */
trait HasSyncSequence
{
    public static function bootHasSyncSequence(): void
    {
        static::saving(function ($model) {
            $model->sync_seq = self::nextSyncSeq(self::resolveTenantId($model));
        });
    }

    /**
     * Whose counter to draw from.
     *
     * Eloquent fires `saving` BEFORE `creating`, and BelongsToTenant sets
     * business_id on `creating` — so on a brand-new model the attribute is
     * still null here and the container binding is the only source. Removing
     * this fallback breaks every insert.
     */
    private static function resolveTenantId($model): string
    {
        $tenantId = $model->business_id ?? app('tenant.id');

        if ($tenantId === null) {
            throw new RuntimeException(
                'Cannot draw a sync_seq without a tenant: '.$model::class.
                ' is synced, and every synced row belongs to exactly one business.'
            );
        }

        return (string) $tenantId;
    }

    /**
     * Atomically take the next value for one tenant.
     *
     * UPDATE ... LAST_INSERT_ID(value + 1) is MySQL's sequence idiom: the
     * increment and the read are one statement, so two concurrent writers
     * cannot take the same number. LAST_INSERT_ID() is per-connection, so the
     * read afterwards is not racy either.
     *
     * The INSERT IGNORE makes it self-healing — a tenant with no counter row
     * gets one rather than silently receiving sync_seq 0 forever.
     */
    private static function nextSyncSeq(string $tenantId): int
    {
        DB::insert(
            'INSERT IGNORE INTO sync_sequences (business_id, value) VALUES (?, 0)',
            [$tenantId]
        );

        DB::update(
            'UPDATE sync_sequences SET value = LAST_INSERT_ID(value + 1) WHERE business_id = ?',
            [$tenantId]
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS v')->v;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `./vendor/bin/pest tests/Feature/Sync/SyncSequenceTest.php`
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Traits/HasSyncSequence.php tests/Feature/Sync/SyncSequenceTest.php
git commit -m "refactor: draw sync_seq from a per-tenant counter

MySQL has no sequences. A single global counter row would serialise every
write on the platform against one row lock; sync_seq only needs to be
monotonic within a tenant, because the delta pull is always scoped by
business_id.

Resolves the tenant as business_id ?? app('tenant.id') because Eloquent
fires saving BEFORE creating, so a new model's business_id is still null
when the sequence is drawn."
```

---

### Task 4: Repoint the test harness — the branch goes green from here

**Files:**
- Modify: `tests/RefreshesTenantDatabase.php`
- Modify: ~105 test files using `on('pgsql_migrate')` / `setConnection('pgsql_migrate')`

- [ ] **Step 1: Rewrite the harness docblock and connection**

In `tests/RefreshesTenantDatabase.php`, the `migrate:fresh` call becomes:

```php
            Artisan::call('migrate:fresh', [
                '--force' => true,
            ]);
```

Replace the docblock, which currently explains a three-reason Postgres problem that no longer exists:

```php
/**
 * Resets the test database between tests without wrapping each test in a
 * transaction.
 *
 * Laravel's RefreshDatabase wraps each test in a transaction and rolls back.
 * This suite instead migrates once per run and TRUNCATEs between tests, so
 * every write genuinely commits.
 *
 * That mattered enormously under Postgres, where setup code wrote through a
 * privileged second connection to bypass RLS and an outer transaction would
 * have hidden those rows. With MySQL there is one connection and no RLS, so the
 * historical reason is gone — but the behaviour is kept because ~900 tests rely
 * on writes being visible across connections and on SetTenantContext's own
 * transaction not nesting inside a test transaction.
 */
```

- [ ] **Step 2: Strip the connection pin from every test**

```bash
cd backend
sed -i "s/::on('pgsql_migrate')//g" tests/**/*.php tests/*.php
sed -i "/->setConnection('pgsql_migrate');/d" tests/**/*.php tests/*.php
sed -i "s/DB::connection('pgsql_migrate')->/DB::/g" tests/**/*.php tests/*.php
sed -i "s/'--database' => 'pgsql_migrate',//g" tests/**/*.php tests/*.php
```

- [ ] **Step 3: Verify**

Run: `grep -rn "pgsql" tests/ || echo CLEAN`
Expected: `CLEAN`. Anything left is a shape the `sed` missed — fix by hand.

- [ ] **Step 4: Run the suite to see where you actually are**

Run: `./vendor/bin/pest 2>&1 | tail -20`
Expected: it now **runs**. Many failures are expected — the tenant GUC calls (Task 5) and RLS tests (Task 11) are still there. Record the number; it is the baseline for the rest of the plan.

- [ ] **Step 5: Commit**

```bash
git add tests/
git commit -m "test: run the suite on the single MySQL connection

Removes the pgsql_migrate pin from ~105 test files. Keeps the
migrate-once-then-truncate strategy: its original reason (setup code
bypassing RLS on a second connection) is gone, but ~900 tests rely on
writes committing rather than rolling back."
```

---

### Task 5: Remove the tenant GUCs

**Files:**
- Modify: `app/Support/TenantContext.php`
- Modify: `app/Http/Middleware/SetTenantContext.php:56` and `:72`

- [ ] **Step 1: Rewrite `TenantContext`**

MySQL has no session GUCs. Tenant identity now lives solely in the container binding the app already reads.

```php
<?php
// app/Support/TenantContext.php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Where the current tenant lives.
 *
 * Under Postgres this set a transaction-scoped GUC (`app.current_tenant`) that
 * RLS policies read, so the database itself knew the tenant. MySQL has no RLS
 * and no GUCs: the tenant now exists ONLY as the container binding
 * `app('tenant.id')`, which BelongsToTenant reads. That is the whole isolation
 * mechanism — see that trait's docblock.
 */
class TenantContext
{
    /**
     * Re-point the current request at a specific business. Used by endpoints
     * (create-business, invite-accept) that must write for a business other
     * than the caller's active `tid`.
     */
    public static function switchTo(string $businessId): void
    {
        app()->bind('tenant.id', fn () => $businessId);
    }

    /**
     * Run $callback for a user with no tenant selected.
     *
     * Public auth routes (login, otp/verify) resolve a user's memberships
     * before any tenant exists. Under Postgres this set a user GUC to activate
     * the memberships policy's user_id branch; now it simply binds the user and
     * runs, because there is no policy to activate.
     *
     * NOTE: Task 6 wraps this callback in Tenancy::withoutTenant() once that
     * class exists — membership lookups here run with no tenant bound, which
     * the fail-closed scope will otherwise refuse. It is left unwrapped at this
     * step only because Tenancy does not exist yet.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function forUser(int $userId, callable $callback): mixed
    {
        return DB::transaction(function () use ($userId, $callback) {
            app()->bind('tenant.user_id', fn () => $userId);

            return $callback();
        });
    }
}
```

- [ ] **Step 2: Delete both `set_config` calls from the middleware**

In `app/Http/Middleware/SetTenantContext.php`, delete this line (~56) and its two-line comment above it:

```php
            DB::statement("select set_config('app.current_user_id', ?, true)", [(string) $userId]);
```

and this one (~72), keeping the two `app()->bind(...)` lines that follow it:

```php
                DB::statement("select set_config('app.current_tenant', ?, true)", [(string) $tid]);
```

**Keep** the surrounding `DB::beginTransaction()` / `commit()` / `rollBack()`. It is no longer needed for GUC scoping but still gives each request atomicity.

- [ ] **Step 3: Verify no GUC calls remain**

Run: `grep -rn "set_config\|current_setting" app/ || echo CLEAN`
Expected: `CLEAN`

- [ ] **Step 4: Run the tenancy middleware tests**

Run: `./vendor/bin/pest tests/Feature/Tenancy/TenantContextMiddlewareTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/TenantContext.php app/Http/Middleware/SetTenantContext.php
git commit -m "refactor: hold the tenant in the container, not a database GUC

MySQL has no session GUCs and no policies to read them. The tenant now
exists only as app('tenant.id'), which BelongsToTenant reads -- that
binding is the entire isolation mechanism now."
```

---

### Task 6: Fail-closed tenant scope

**Files:**
- Create: `app/Exceptions/TenantContextMissing.php`
- Create: `app/Support/Tenancy.php`
- Modify: `app/Traits/BelongsToTenant.php`
- Create: `tests/Feature/Tenancy/FailClosedScopeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Tenancy/FailClosedScopeTest.php

use App\Exceptions\TenantContextMissing;
use App\Models\Business;
use App\Models\Customer;
use App\Support\Tenancy;
use Illuminate\Support\Str;

function fcsCustomer(Business $b, string $name): Customer
{
    return Customer::create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'opening_balance' => '0.00',
    ]);
}

it('throws rather than returning every tenant when no tenant is bound', function () {
    // This is the whole migration in one test. Under Postgres an unscoped query
    // was caught by RLS underneath. There is nothing underneath now, so the
    // scope must refuse instead of quietly returning the entire platform.
    $a = Business::factory()->create();
    fcsCustomer($a, 'Ram');

    app()->bind('tenant.id', fn () => null);

    expect(fn () => Customer::query()->get())->toThrow(TenantContextMissing::class);
});

it('scopes normally when a tenant is bound', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();
    fcsCustomer($a, 'Mine');
    fcsCustomer($b, 'Theirs');

    app()->bind('tenant.id', fn () => $a->id);

    expect(Customer::query()->pluck('name')->all())->toBe(['Mine']);
});

it('lets an explicit withoutTenant block read across tenants', function () {
    // The four legitimate cross-tenant paths: seeders, the platform console,
    // auth before tenant selection, and the inbound WhatsApp STOP write.
    $a = Business::factory()->create();
    $b = Business::factory()->create();
    fcsCustomer($a, 'Mine');
    fcsCustomer($b, 'Theirs');

    app()->bind('tenant.id', fn () => null);

    $names = Tenancy::withoutTenant(fn () => Customer::query()->pluck('name')->sort()->values()->all());

    expect($names)->toBe(['Mine', 'Theirs']);
});

it('restores the fail-closed state after the block, even when it throws', function () {
    // A leaked escape hatch would silently disable isolation for the rest of
    // the request, which is worse than never having had one.
    app()->bind('tenant.id', fn () => null);

    try {
        Tenancy::withoutTenant(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    expect(fn () => Customer::query()->get())->toThrow(TenantContextMissing::class);
});

it('still stamps business_id on create from the bound tenant', function () {
    $a = Business::factory()->create();
    app()->bind('tenant.id', fn () => $a->id);

    $c = Customer::create([
        'uuid' => (string) Str::uuid(), 'name' => 'Ram', 'opening_balance' => '0.00',
    ]);

    expect($c->business_id)->toBe($a->id);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Tenancy/FailClosedScopeTest.php`
Expected: FAIL — `TenantContextMissing` and `Tenancy` do not exist.

- [ ] **Step 3: Create the exception**

```php
<?php
// app/Exceptions/TenantContextMissing.php

namespace App\Exceptions;

use RuntimeException;

/**
 * A tenant-owned model was queried with no tenant bound.
 *
 * Under Postgres this was harmless — RLS returned nothing regardless. On MySQL
 * an unscoped query returns EVERY tenant's rows, so this is a hard failure
 * rather than a warning. If the query is legitimately cross-tenant, wrap it in
 * Tenancy::withoutTenant() so the intent is explicit and greppable.
 */
class TenantContextMissing extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(
            "Refusing to query {$model} with no tenant bound: this would return every ".
            'tenant\'s rows. Wrap the call in Tenancy::withoutTenant() if it is '.
            'deliberately cross-tenant.'
        );
    }
}
```

- [ ] **Step 4: Create the escape hatch**

```php
<?php
// app/Support/Tenancy.php

namespace App\Support;

/**
 * The only sanctioned way to run a query across tenants.
 *
 * Exactly four paths legitimately need it, and they are the audit surface that
 * replaced 23 RLS policies:
 *
 *   1. Seeders                  — run outside any request
 *   2. The superadmin console   — cross-tenant by design
 *   3. Auth before tenant selection (TenantContext::forUser)
 *   4. Inbound WhatsApp STOP    — one number, every tenant holding it
 *
 * Deliberately a named class rather than a flag, so `grep -rn withoutTenant`
 * answers "where can isolation be bypassed?" in one command.
 */
class Tenancy
{
    private static bool $suspended = false;

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutTenant(callable $callback): mixed
    {
        $previous = self::$suspended;
        self::$suspended = true;

        try {
            return $callback();
        } finally {
            // finally, not a trailing assignment: a throwing callback must not
            // leave isolation disabled for the rest of the request.
            self::$suspended = $previous;
        }
    }

    public static function isSuspended(): bool
    {
        return self::$suspended;
    }
}
```

- [ ] **Step 5: Invert the scope**

Replace `app/Traits/BelongsToTenant.php`:

```php
<?php
// app/Traits/BelongsToTenant.php

namespace App\Traits;

use App\Exceptions\TenantContextMissing;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scopes every query to the bound tenant, and REFUSES to run if there is none.
 *
 * This used to fail open — no tenant meant no predicate, so the query returned
 * every tenant's rows. That was safe only because Postgres RLS refused
 * underneath it. On MySQL there is nothing underneath, so the same code would
 * be a cross-tenant data leak; it now throws instead.
 *
 * Legitimately cross-tenant work goes through Tenancy::withoutTenant(), which
 * is greppable. Note this binds Eloquent only: a raw DB::table() walks straight
 * past it, which is what the query tripwire in QueryTripwireServiceProvider
 * exists to catch in tests.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (Tenancy::isSuspended()) {
                return;
            }

            $tenantId = app('tenant.id');

            if ($tenantId === null) {
                throw TenantContextMissing::for($builder->getModel()::class);
            }

            $builder->where($builder->getModel()->getTable().'.business_id', $tenantId);
        });

        static::creating(function ($model) {
            if (empty($model->business_id)) {
                $model->business_id = app('tenant.id');
            }
        });
    }
}
```

- [ ] **Step 6: Run the test**

Run: `./vendor/bin/pest tests/Feature/Tenancy/FailClosedScopeTest.php`
Expected: 5 passed.

- [ ] **Step 7: Wrap the four legitimate call sites**

Run the suite and fix the `TenantContextMissing` failures by wrapping only these:

Run: `./vendor/bin/pest 2>&1 | grep -c TenantContextMissing`

- `database/seeders/ShreeRajShyamajiSeeder::run()` and `DatabaseSeeder::run()` — wrap the body
- `app/Support/TenantContext::forUser()` — wrap the callback
- The WhatsApp STOP handler in `app/Http/Controllers/WhatsAppWebhookController.php`
- Platform console controllers (Task 7 covers these)

Example, in `DatabaseSeeder::run()`:

```php
    public function run(): void
    {
        Tenancy::withoutTenant(function () {
            $this->platformAdmin();
            $this->call(ShreeRajShyamajiSeeder::class);
        });

        $this->command->info('Seeded owner@vyaparbook.test / password123');
        $this->command->info('Superadmin: admin@vyaparbook.test / password123');
    }
```

**Do not** wrap anything else to make a test pass. Every extra wrap is a hole in the only isolation layer left.

- [ ] **Step 8: Commit**

```bash
git add app/Exceptions/TenantContextMissing.php app/Support/Tenancy.php \
        app/Traits/BelongsToTenant.php tests/Feature/Tenancy/FailClosedScopeTest.php \
        database/seeders/ app/Support/TenantContext.php
git commit -m "feat: make the tenant scope fail closed

BelongsToTenant used to fail OPEN: no tenant bound meant no predicate, so
the query returned every tenant's rows. That was safe only because RLS
refused underneath. With MySQL there is nothing underneath, so it throws.

Cross-tenant work goes through Tenancy::withoutTenant() -- a named,
greppable escape used at exactly four sites, which is the audit surface
that replaced 23 RLS policies."
```

---

### Task 7: Move the platform console to the read-only connection

**Files:**
- Modify: `app/Http/Controllers/Web/Admin/ConsoleController.php:33,61`
- Modify: `app/Http/Controllers/Web/Admin/TenantActionController.php:148`
- Modify: `app/Http/Controllers/Api/V1/Admin/TenantController.php:34,85`
- Modify: `app/Http/Controllers/Api/V1/Admin/ImpersonationController.php:42`
- Modify: `app/Platform/PlatformTenantContext.php:12` (comment)

- [ ] **Step 1: Rename the connection at every call site**

```bash
cd backend
grep -rl "pgsql_platform" app/ | xargs sed -i "s/pgsql_platform/mysql_platform/g"
```

- [ ] **Step 2: Correct the now-false comments**

Three files describe the connection as "SELECT-only BYPASSRLS". There is no RLS to bypass. Replace that phrase with:

```php
 * Reads run on the SELECT-only connection (mysql_platform): the console is
 * cross-tenant by design, and its database user is granted SELECT and nothing
 * else, so it cannot mutate a tenant's data however wrong this code gets.
```

- [ ] **Step 3: Wrap the console's cross-tenant reads**

These controllers query tenant-owned models with no tenant bound, so they need the escape hatch. In each `index`/`show` method, wrap the query body:

```php
        $tenants = Tenancy::withoutTenant(fn () => DB::connection('mysql_platform')
            ->table('businesses')
            // ... existing query unchanged
            ->get());
```

Add `use App\Support\Tenancy;` to each file.

- [ ] **Step 4: Run the console tests**

Run: `./vendor/bin/pest tests/Feature/Web/AdminConsoleTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/
git commit -m "refactor: point the platform console at the read-only MySQL user

pgsql_platform logged in as a SELECT-only BYPASSRLS role. The bypass half
is meaningless without RLS; the read-only half survives as a MySQL GRANT
SELECT user, so the console still cannot mutate tenant data. Cross-tenant
READ scoping becomes the app's job, via Tenancy::withoutTenant()."
```

---

### Task 8: Translate the raw SQL

**Files:**
- Modify: the 35 `selectRaw` blocks, chiefly in `app/Services/DashboardReportService.php`, `app/Services/CogsService.php`, `app/Services/FinishedGoodsService.php`, `app/Services/CashFlowService.php`

- [ ] **Step 1: Find every Postgres idiom**

Run: `grep -rn "::text\|::int\|::uuid\|ILIKE\|jsonb" app/ --include=*.php`

- [ ] **Step 2: Apply the substitutions**

| Postgres | MySQL |
|---|---|
| `sum(x)::text` | `CAST(SUM(x) AS CHAR)` |
| `coalesce(sum(x), 0)::text` | `CAST(COALESCE(SUM(x), 0) AS CHAR)` |
| `extract(month from d)::int as m` | `EXTRACT(MONTH FROM d) as m` |
| `x::uuid` | drop the cast — `CHAR(36)` compares directly |

Worked example — `app/Services/FinishedGoodsService.php:39`:

```php
// Before
->selectRaw('product_id, coalesce(sum(output_kg), 0)::text as kg')

// After
->selectRaw('product_id, CAST(COALESCE(SUM(output_kg), 0) AS CHAR) as kg')
```

The `::text` casts exist so bcmath receives strings rather than floats. `CAST(... AS CHAR)` preserves that; **do not simply delete the cast.**

- [ ] **Step 3: Verify none remain**

Run: `grep -rn "::text\|::int\|::uuid" app/ --include=*.php || echo CLEAN`
Expected: `CLEAN`

- [ ] **Step 4: Run the reporting tests**

Run: `./vendor/bin/pest tests/Feature/Reports tests/Unit`
Expected: PASS. Failures here usually mean a figure came back as a float — check the cast.

- [ ] **Step 5: Commit**

```bash
git add app/Services/
git commit -m "refactor: translate raw SQL from Postgres to MySQL

Casts become CAST(... AS CHAR) rather than being dropped: they exist so
bcmath receives decimal strings, not floats. FOR UPDATE is unchanged --
InnoDB supports it, so gapless invoice numbering survives intact."
```

---

### Task 9: Pin the decimal contract

**Files:**
- Create: `tests/Feature/Database/DecimalFidelityTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/Database/DecimalFidelityTest.php

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * The smallest test in the suite, guarding the largest thing.
 *
 * Every rupee is a decimal STRING run through bcmath so money never touches a
 * float. Postgres returned DECIMAL as a string; MySQL does too, but ONLY with
 * native prepares. Turn on PDO::ATTR_EMULATE_PREPARES and decimals arrive as
 * floats: nothing throws, and khatas drift by paise over months.
 */

it('returns DECIMAL columns as PHP strings, not floats', function () {
    $row = DB::select('SELECT CAST(1234.56 AS DECIMAL(12,2)) AS d')[0];

    expect($row->d)->toBeString();
});

it('round-trips a stored money column without float drift', function () {
    $b = Business::factory()->create();
    app()->bind('tenant.id', fn () => $b->id);

    Customer::create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ram', 'opening_balance' => '99999999.99',
    ]);

    $raw = DB::table('customers')->value('opening_balance');

    expect($raw)->toBeString();
    // Exact string equality: a float round-trip would land on 100000000.0 or
    // 99999999.990000001 and this would fail loudly.
    expect((string) $raw)->toBe('99999999.99');
});

it('has emulated prepares disabled on the app connection', function () {
    // Belt and braces: the two tests above would start passing again by
    // accident if someone re-enabled emulation and MySQL happened to return a
    // string. This asserts the setting itself.
    $pdo = DB::connection()->getPdo();

    expect($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES))->toBeFalsy();
});
```

- [ ] **Step 2: Run it**

Run: `./vendor/bin/pest tests/Feature/Database/DecimalFidelityTest.php`
Expected: 3 passed. If the first fails, revisit Task 1 Step 4 before going further — everything downstream of this is money.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Database/DecimalFidelityTest.php
git commit -m "test: pin DECIMAL columns to arrive as strings

41 decimal columns and ~2,700 bcmath assertions depend on PDO returning
DECIMAL as a string. Emulated prepares would return floats instead, and
nothing would throw -- khatas would just drift by paise."
```

---

### Task 10: Query tripwire for raw builder leaks

**Files:**
- Create: `app/Providers/QueryTripwireServiceProvider.php`
- Modify: `bootstrap/providers.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Tenancy/FailClosedScopeTest.php`:

```php
it('trips on a raw builder query that bypasses the global scope', function () {
    // A global scope binds Eloquent only. DB::table() walks straight past it,
    // and the report services use raw builders -- so this is the hole the
    // scope structurally cannot close.
    $b = Business::factory()->create();
    app()->bind('tenant.id', fn () => $b->id);

    expect(fn () => DB::table('customers')->get())
        ->toThrow(RuntimeException::class, 'without a business_id predicate');
});

it('allows a raw query that does scope itself', function () {
    $b = Business::factory()->create();
    app()->bind('tenant.id', fn () => $b->id);

    expect(DB::table('customers')->where('business_id', $b->id)->get())->toHaveCount(0);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Tenancy/FailClosedScopeTest.php --filter=trips`
Expected: FAIL — no exception thrown.

- [ ] **Step 3: Build the tripwire**

```php
<?php
// app/Providers/QueryTripwireServiceProvider.php

namespace App\Providers;

use App\Support\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Fails any test whose query touches a tenant table without scoping it.
 *
 * BelongsToTenant binds Eloquent; a raw DB::table() does not go through it, and
 * the report services use raw builders throughout. Under Postgres that gap was
 * covered by RLS. Nothing covers it now, so this catches it in CI instead.
 *
 * Registered ONLY in the test environment. It is a development tripwire, not a
 * runtime guard: string-matching SQL is too blunt to gate production traffic
 * on, and a false positive there would take the shop down.
 */
class QueryTripwireServiceProvider extends ServiceProvider
{
    /**
     * The tables that were RLS-protected under Postgres.
     *
     * 27 tables were covered by 23 policy statements — several migrations apply
     * one statement across a pair of tables, so counting statements undercounts
     * the surface. This list is the tables, extracted with:
     *
     *   git show master:... | grep -oP 'ALTER TABLE \K[a-z_]+(?= ENABLE ROW LEVEL)'
     *
     * `memberships` is deliberately EXCLUDED. Its Postgres policy had a
     * user_id branch as well as a business_id one — that is why
     * TenantContext::forUser exists — so `memberships WHERE user_id = ?` is a
     * legitimate unscoped-by-business query and would false-positive here.
     */
    private const TENANT_TABLES = [
        'customers', 'sales', 'sale_lines', 'payments', 'orders', 'order_lines',
        'products', 'pack_sizes', 'product_packs', 'raw_materials',
        'stock_movements', 'production_batches', 'material_consumptions',
        'suppliers', 'supplier_payments', 'purchases', 'expenses',
        'invoices', 'invoice_lines', 'invoice_counters',
        'beats', 'beat_customers', 'reminder_logs', 'reminder_batches',
        'subscriptions', 'subscription_payments',
    ];

    public function boot(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        Event::listen(function (QueryExecuted $query) {
            if (Tenancy::isSuspended()) {
                return;
            }

            $sql = strtolower($query->sql);

            // Reads only. Writes are covered by BelongsToTenant stamping
            // business_id on create, and an UPDATE/DELETE without a predicate
            // is caught by the scope on the model it came from.
            if (! str_starts_with($sql, 'select')) {
                return;
            }

            foreach (self::TENANT_TABLES as $table) {
                $touches = str_contains($sql, " from `{$table}`")
                    || str_contains($sql, " join `{$table}`");

                if ($touches && ! str_contains($sql, 'business_id')) {
                    throw new RuntimeException(
                        "Tenant leak: query touched `{$table}` without a business_id ".
                        "predicate.\nSQL: {$query->sql}\n".
                        'Scope it, or wrap deliberately cross-tenant work in '.
                        'Tenancy::withoutTenant().'
                    );
                }
            }
        });
    }
}
```

- [ ] **Step 4: Register it**

In `bootstrap/providers.php`, add to the returned array:

```php
    App\Providers\QueryTripwireServiceProvider::class,
```

- [ ] **Step 5: Run the test**

Run: `./vendor/bin/pest tests/Feature/Tenancy/FailClosedScopeTest.php`
Expected: 7 passed.

- [ ] **Step 6: Run the whole suite and fix what trips**

Run: `./vendor/bin/pest 2>&1 | grep -A3 "Tenant leak" | head -40`

Each hit is a genuine unscoped raw query. Fix by adding `->where('business_id', ...)`, **not** by wrapping in `withoutTenant()` unless the query is truly cross-tenant.

- [ ] **Step 7: Commit**

```bash
git add app/Providers/QueryTripwireServiceProvider.php bootstrap/providers.php \
        tests/Feature/Tenancy/FailClosedScopeTest.php app/Services/
git commit -m "test: trip on raw queries that bypass the tenant scope

A global scope binds Eloquent only; DB::table() walks past it and the
report services use raw builders. RLS covered that gap before. This
catches it in CI -- test environment only, because string-matching SQL is
too blunt to gate production traffic on."
```

---

### Task 11: Delete the tests that proved the database enforced isolation

**Files:**
- Delete: `tests/Feature/Tenancy/BillingRlsTest.php` (4 tests)
- Delete: `tests/Feature/Tenancy/CatalogRlsTest.php` (4)
- Delete: `tests/Feature/Tenancy/KhataRlsTest.php` (4)
- Delete: `tests/Feature/Tenancy/MembershipRlsTest.php` (2)
- Delete: `tests/Feature/Tenancy/StockRlsTest.php` (4)
- Delete: `tests/Feature/Tenancy/PgBouncerPooledConnectionTest.php` (1)
- Modify: `tests/Feature/Tenancy/CrossTenantLeakTest.php` (comments only)

- [ ] **Step 1: Understand why these cannot be ported before deleting them**

`StockRlsTest`'s header states its own purpose: *"Proves the stock & production RLS policies themselves, with the app layer bypassed — the global scope cannot mask whether RLS is doing the work."* One test is literally *"hides another business stock rows even with the app layer bypassed."*

On MySQL there is nothing behind the app layer. These tests have no subject. Rewriting them to pass would produce tests that assert nothing — worse than deleting them, because the file name would still claim isolation is proven.

- [ ] **Step 2: Delete them in one commit**

```bash
cd backend
git rm tests/Feature/Tenancy/BillingRlsTest.php \
       tests/Feature/Tenancy/CatalogRlsTest.php \
       tests/Feature/Tenancy/KhataRlsTest.php \
       tests/Feature/Tenancy/MembershipRlsTest.php \
       tests/Feature/Tenancy/StockRlsTest.php \
       tests/Feature/Tenancy/PgBouncerPooledConnectionTest.php
```

- [ ] **Step 3: Correct the two misleading comments in `CrossTenantLeakTest`**

These ~20 tests survive — they drive the HTTP API with both layers live, so they assert behaviour, not mechanism. But two comments name the wrong mechanism:

```php
// Before (lines ~204 and ~286)
    // 404, not 403: RLS hides B's customer, so findOrFail genuinely finds nothing.

// After
    // 404, not 403: the tenant scope adds a business_id predicate, so B's
    // customer is genuinely not found rather than found-and-refused.
```

- [ ] **Step 4: Confirm the survivors still pass**

Run: `./vendor/bin/pest tests/Feature/Tenancy`
Expected: `CrossTenantLeakTest`, `TenantContextMiddlewareTest` and `FailClosedScopeTest` all pass; 19 fewer tests than the baseline recorded in Task 4.

- [ ] **Step 5: Commit**

```bash
git commit -am "test: delete the 19 tests that proved the DATABASE enforced isolation

These asserted that Postgres refused cross-tenant reads with the app
layer deliberately bypassed. On MySQL there is nothing behind the app
layer, so they have no subject.

Deleted rather than rewritten: a passing test in a file called
StockRlsTest would claim isolation is proven when it is not. What
replaces them is FailClosedScopeTest plus the query tripwire.

CrossTenantLeakTest's ~20 tests survive -- they drive the HTTP API with
both layers live -- with two comments corrected to name the scope rather
than RLS as the mechanism."
```

---

### Task 12: Reconcile comments and docs, then close the gate

**Files:**
- Modify: ~43 files in `app/` with RLS comments
- Modify: `CLAUDE.md`, `docs/PRD.md` §4.1–4.3
- Modify: `docs/ui-backlog.md`

- [ ] **Step 1: Find every stale claim**

Run: `grep -rn "RLS\|row level security\|Postgres" app/ --include=*.php -i`
Expected: ~43 files.

- [ ] **Step 2: Fix the actively misleading ones first**

These describe a protection that no longer exists. Two worked examples:

```php
// app/Services/LedgerWriter.php — before
            // RLS makes a cross-tenant pack invisible to whereIn() exactly as it
            // was to findOrFail(), so the 404-on-foreign-pack behaviour is unchanged.

// after
            // The tenant scope adds a business_id predicate to whereIn() exactly
            // as it does to findOrFail(), so the 404-on-foreign-pack behaviour is
            // unchanged. NOTE: app-enforced only -- there is no database policy
            // behind this since the move to MySQL.
```

```php
// app/Services/OrderWriter.php — before
        // findOrFail under RLS: another tenant's customer is invisible → 404.

// after
        // findOrFail under the tenant scope: another tenant's customer is not
        // matched → 404.
```

A wrong comment about security is worse than no comment. Do not skip this because the tests stay green either way.

- [ ] **Step 3: Correct `CLAUDE.md`**

Two lines are now false:

```markdown
- PostgreSQL (Row-Level Security for tenant isolation)
```
becomes
```markdown
- MySQL 8 (tenant isolation is app-enforced — see `App\Traits\BelongsToTenant`)
```

and:

```markdown
- Multi-tenant isolation: every tenant-owned table enforces RLS AND an app-level tenant scope (defense in depth) — never rely on one layer alone
```
becomes
```markdown
- Multi-tenant isolation: enforced by `App\Traits\BelongsToTenant`, which FAILS CLOSED — it throws rather than returning every tenant's rows when no tenant is bound. Cross-tenant work must go through `Tenancy::withoutTenant()` (four sanctioned sites). A test-environment query tripwire catches raw builders that bypass the scope. There is no database-level layer behind this: MySQL has no RLS.
```

- [ ] **Step 4: Mark PRD §4 superseded**

Strike through §4.1 (RLS implementation), §4.2 (the PgBouncer gotcha — PgBouncer leaves the stack) and §4.3 (tenant context propagation), each pointing at the migration spec, in the style the order-workflow spec used for its superseded decisions.

- [ ] **Step 5: Add the backlog entry**

Add an `F-20` row to `docs/ui-backlog.md` recording the migration, what was given up (engine-enforced isolation, 19 tests, lock-free sequence allocation) and what replaced it.

- [ ] **Step 6: Close the acceptance gate**

Run: `grep -rn "pgsql" app/ database/ config/ tests/ routes/ || echo CLEAN`
Expected: `CLEAN`. **If this returns anything, the migration is not finished.**

- [ ] **Step 7: Full verification**

```bash
cd backend
php artisan migrate:fresh --seed --force
./vendor/bin/pest
npx vitest run
npm run build
```

Expected: migrations and seed succeed with no `--database` flag; PHP suite green at roughly 876 + ~12 new tests; 193 JS tests pass; build clean.

- [ ] **Step 8: Manual pass against reseeded data**

Log in as `owner@vyaparbook.test` / `password123` and check a customer khata, `/pricing`, `/orders`, and the superadmin console. Confirm outstanding figures and margins read as they did on Postgres.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "docs: reconcile comments and docs with the MySQL move

Sweeps 43 files whose comments still described RLS as a live protection.
The tests stay green either way, which is exactly why this is easy to
skip -- and why a wrong comment about security is worse than none.

Rewrites CLAUDE.md's defense-in-depth rule, which is no longer
achievable, to describe the fail-closed scope and the tripwire instead.
Marks PRD 4.1-4.3 superseded."
```

---

## Post-plan verification

The migration is done when all of these hold:

- [ ] `grep -rn "pgsql" app/ database/ config/ tests/ routes/` → nothing
- [ ] `php artisan migrate:fresh --seed --force` → succeeds, no `--database` flag
- [ ] `./vendor/bin/pest` → green
- [ ] `grep -rn "withoutTenant" app/ database/` → **only** the four sanctioned sites plus the console controllers
- [ ] `DecimalFidelityTest` passes — the money model is intact
- [ ] `CLAUDE.md` no longer claims two layers of isolation
