# Tenant Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build VyaparBook's tenant-configurable catalog — `Product`/`PackSize`/`ProductPack` with RLS + app-level tenant scoping, an aggregate `GET /catalog` read, granular owner/admin CRUD with archive/restore, and template seeding that makes a new tenant sellable in minutes.

**Architecture:** Three tenant-owned tables in the existing Laravel 11 backend, each carrying a flat RLS policy (`business_id = current_setting('app.current_tenant')`) plus the `BelongsToTenant` global scope — this slice is that trait's first real consumer. All routes sit behind `auth:api` + `tenant.context` + `require.tenant`, so `SetTenantContext` has already opened the request transaction and set the tenant GUC before any catalog code runs; nothing here calls `TenantContext::switchTo()`. Templates are versioned PHP data files under `database/catalog_templates/`, applied by a service that inserts ordinary tenant rows.

**Testing:** Pest, following the tenancy slice's conventions exactly — no `RefreshDatabase` (see `docs/superpowers/specs/2026-07-04-tenancy-auth-core-design.md` §7), `RefreshesTenantDatabase` applied via `tests/Pest.php`, and `Model::on('pgsql_migrate')` (superuser, bypasses RLS) for setup rows.

**Tech Stack:** PHP 8.3, Laravel 11, PostgreSQL (RLS), Pest, bcmath (verified present — used for exact rupee math).

**Spec:** `docs/superpowers/specs/2026-07-15-catalog-design.md`

---

## Progress (updated 2026-07-17)

**16 of 18 tasks complete.** The full catalog slice — models, RLS, CRUD, aggregate read, templates and seeding — is built, committed, and green against a live Postgres, and the DB-level RLS proof is in place. What remains is the cross-tenant leak-suite cases (Task 17) and the close-out (Task 18).

| # | Task | Status | Commit |
|---|------|--------|--------|
| 1 | Container defaults for `tenant.*` bindings | ✅ Done | `7ce70de` |
| 2 | `TenantAwareJob` binds the app-level tenant | ✅ Done | `2bf6b5d` |
| 3 | `HasVersion` trait | ✅ Done | `4c88963` |
| 4 | Product model, migration, factory | ✅ Done | `9f1382f` |
| 5 | PackSize model, migration, factory | ✅ Done | `184783d` |
| 6 | ProductPack model, migration, factory | ✅ Done | `7974b0b` |
| 7 | `CatalogService::suggestedCostPrice` | ✅ Done | `00c0cfb` |
| 8 | `CatalogPolicy` | ✅ Done | `7475002` |
| 9 | Product CRUD + archive/restore | ✅ Done | `7f434fe` |
| 10 | RBAC coverage for catalog writes | ✅ Done | `cbf5dc9` |
| 11 | PackSize CRUD + archive/restore | ✅ Done | `73057cc` |
| 12 | ProductPack CRUD + archive/restore | ✅ Done | `2079db2` |
| 13 | `GET /catalog` aggregate read | ✅ Done | `7d1520c` |
| 14 | Catalog templates + `CatalogTemplateService` | ✅ Done | `dc1b0aa` |
| 15 | `POST /catalog/seed` endpoint | ✅ Done | `2f4aabf` |
| 16 | DB-level RLS proof (`CatalogRlsTest`) | ✅ Done | `083b403` |
| 17 | Catalog cases in the cross-tenant leak suite | ⬜ Pending | — |
| 18 | Full suite, docs, plan close-out | ⬜ Pending | — |

**Verified:** `tests/Feature/Catalog` — 33 passed (74 assertions) on 2026-07-17.

---

## Two pre-existing gaps this slice must close first

Tasks 1 and 2 are not catalog features. They fix container/queue behaviour that has never fired because **no model used `BelongsToTenant` until now**. Both were verified against the running app, not assumed:

1. `app('tenant.id')` **throws** `BindingResolutionException: Target class [tenant.id] does not exist` when unbound (confirmed via tinker). Laravel resolves an unbound string key by trying to construct it as a class. `SetTenantContext` binds it per request, so nothing has hit this — but the moment a `BelongsToTenant` model is touched outside a request (a factory in a test, a seeder, a console command), its global scope calls `app('tenant.id')` and explodes. Every catalog test would fail on setup.
2. `TenantAwareJob` calls `TenantContext::switchTo()`, which sets the Postgres GUC but **never binds `tenant.id`**. A queued job touching a catalog model would therefore run with the DB layer scoped and the app layer either throwing or blind — losing the defense-in-depth that CLAUDE.md requires both layers to provide.

---

## File Structure

```
backend/
  app/
    Models/
      Product.php                    (new)
      PackSize.php                   (new)
      ProductPack.php                (new)
    Http/Controllers/Api/V1/
      CatalogController.php          (new — index, seed)
      ProductController.php          (new — store, update, destroy, restore)
      PackSizeController.php         (new)
      ProductPackController.php      (new)
    Services/
      CatalogService.php             (new — suggestedCostPrice)
      CatalogTemplateService.php     (new — available, apply)
    Policies/
      CatalogPolicy.php              (new — manage())
    Traits/
      HasVersion.php                 (new)
      TenantAwareJob.php             (modified — bind app-level tenant)
    Providers/
      AppServiceProvider.php         (modified — tenant.* null defaults)
  database/
    migrations/
      2026_07_15_000001_create_products_table.php
      2026_07_15_000002_create_pack_sizes_table.php
      2026_07_15_000003_create_product_packs_table.php
    factories/
      ProductFactory.php
      PackSizeFactory.php
      ProductPackFactory.php
    catalog_templates/
      namkeen.php
      sweets.php
      spices.php
  routes/api.php                     (modified)
  tests/
    Unit/
      TenantContainerDefaultsTest.php
      TenantAwareJobTest.php         (modified — add container-binding case)
      HasVersionTraitTest.php
      CatalogServiceTest.php
    Feature/
      Catalog/CatalogCrudTest.php
      Catalog/CatalogRbacTest.php
      Catalog/CatalogArchiveTest.php
      Catalog/CatalogReadTest.php
      Catalog/CatalogTemplateTest.php
      Tenancy/CatalogRlsTest.php
      Tenancy/CrossTenantLeakTest.php  (modified — 3 catalog cases)
```

**Why `business_id` is in `$fillable` on all three models:** Laravel factories construct via `new Model($attributes)`, which runs `fill()` and therefore respects `$fillable` — a non-fillable `business_id` is silently dropped, `BelongsToTenant` then stamps `null`, and the insert dies on a NOT NULL violation. `Membership` already solves it this way. Client input can never reach it regardless: every controller passes an explicit `$request->validate()` whitelist, and the RLS `WITH CHECK` rejects a cross-tenant `business_id` outright. `archived_at` and `version` stay **out** of `$fillable` — they are set by explicit assignment only (the same lesson `InviteController` records for `redeemed_at`).

---

## Task 1: Container defaults for the tenant.* bindings

**Files:**
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Test: `backend/tests/Unit/TenantContainerDefaultsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/TenantContainerDefaultsTest.php

it('resolves the tenant bindings to null outside a request', function () {
    expect(app('tenant.id'))->toBeNull();
    expect(app('tenant.role'))->toBeNull();
    expect(app('tenant.user_id'))->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=TenantContainerDefaultsTest`
Expected: FAIL — `BindingResolutionException: Target class [tenant.id] does not exist.`

- [ ] **Step 3: Bind null defaults in AppServiceProvider**

Replace the body of `register()` in `backend/app/Providers/AppServiceProvider.php`:

```php
    public function register(): void
    {
        // BelongsToTenant's global scope, RequireTenant and CatalogPolicy all read
        // the tenant through the container. Laravel resolves an unbound string key
        // by trying to construct it as a class, so app('tenant.id') outside a
        // request throws "Target class [tenant.id] does not exist" rather than
        // returning null. Binding null defaults makes the contract total: a
        // factory, seeder, console command or unit test sees a well-defined
        // "no tenant", and SetTenantContext overrides these per request.
        //
        // bind(), not instance(): the container checks instances with
        // isset($this->instances[$abstract]), and isset(null) is false — a null
        // instance falls through to construction and throws anyway.
        $this->app->bind('tenant.id', fn () => null);
        $this->app->bind('tenant.role', fn () => null);
        $this->app->bind('tenant.user_id', fn () => null);
    }
```

- [ ] **Step 4: Run the test**

Run: `cd backend && php artisan test --filter=TenantContainerDefaultsTest`
Expected: PASS (1 passed)

- [ ] **Step 5: Run the whole suite to prove nothing regressed**

Run: `cd backend && php artisan test`
Expected: PASS (40 passed) — `SetTenantContext` and `BelongsToTenantTraitTest` both override these bindings, so the defaults must not change existing behaviour.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Providers/AppServiceProvider.php backend/tests/Unit/TenantContainerDefaultsTest.php
git commit -m "fix: bind null defaults for tenant.* container keys"
```

---

## Task 2: TenantAwareJob binds the app-level tenant

**Files:**
- Modify: `backend/app/Traits/TenantAwareJob.php`
- Test: `backend/tests/Unit/TenantAwareJobTest.php`

- [ ] **Step 1: Add a failing test case**

Append to `backend/tests/Unit/TenantAwareJobTest.php`, and add the highlighted line to the existing `FixtureTenantJob` so it observes the container too:

```php
// In FixtureTenantJob, alongside $observedTenant:
    public static ?string $observedContainerTenant = null;

// …and inside handleForTenant(), after the existing assignment:
        self::$observedContainerTenant = app('tenant.id');
```

Then append this test:

```php
it('binds the app-level tenant so BelongsToTenant models are scoped inside a job', function () {
    FixtureTenantJob::$observedContainerTenant = null;

    $job = (new FixtureTenantJob())->withTenant('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
    $job->handle();

    expect(FixtureTenantJob::$observedContainerTenant)->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=TenantAwareJobTest`
Expected: FAIL — the new case gets `null` (Task 1's default), not the tenant id. The pre-existing GUC test still passes.

- [ ] **Step 3: Bind the tenant in the trait**

Replace `handle()` in `backend/app/Traits/TenantAwareJob.php`:

```php
    public function handle(): void
    {
        DB::transaction(function () {
            TenantContext::switchTo($this->tenantId);

            // Bind the app-level tenant too, not just the Postgres GUC. Models
            // using BelongsToTenant read app('tenant.id') for their global scope;
            // with only the GUC set, a job would run with the DB layer scoped and
            // the app layer blind — losing the defense in depth both layers exist
            // to provide. No model used BelongsToTenant before the catalog, which
            // is why this never surfaced.
            app()->bind('tenant.id', fn () => $this->tenantId);

            $this->handleForTenant();
        });
    }
```

- [ ] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter=TenantAwareJobTest`
Expected: PASS (2 passed)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Traits/TenantAwareJob.php backend/tests/Unit/TenantAwareJobTest.php
git commit -m "fix: bind app-level tenant in TenantAwareJob, not just the GUC"
```

---

## Task 3: HasVersion trait

**Files:**
- Create: `backend/app/Traits/HasVersion.php`
- Test: `backend/tests/Unit/HasVersionTraitTest.php`

`version` exists for PRD §9's future delta sync. It is added now because backfilling it onto a table that already holds live tenant data is a migration plus a data fix; adding it now is one column and this trait.

- [ ] **Step 1: Write the trait**

```php
<?php
// app/Traits/HasVersion.php

namespace App\Traits;

/**
 * Bumps an integer `version` column on every update.
 *
 * Kept separate from BelongsToTenant deliberately: versioning and tenant
 * scoping are unrelated concerns, and a future non-tenant model may want
 * versioning without a global scope.
 */
trait HasVersion
{
    public static function bootHasVersion(): void
    {
        static::updating(function ($model) {
            $model->version = (int) $model->version + 1;
        });
    }
}
```

- [ ] **Step 2: Write a failing test against a fixture table**

```php
<?php
// tests/Unit/HasVersionTraitTest.php

use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VersionFixtureItem extends Model
{
    use HasVersion;

    protected $table = 'version_fixture_items';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
}

beforeEach(function () {
    Schema::connection('pgsql_migrate')->create('version_fixture_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->unsignedInteger('version')->default(1);
    });
});

afterEach(function () {
    Schema::connection('pgsql_migrate')->dropIfExists('version_fixture_items');
});

it('starts at version 1', function () {
    $item = VersionFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);

    expect($item->fresh()->version)->toBe(1);
});

it('bumps the version on update', function () {
    $item = VersionFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);

    $item->update(['name' => 'Sev Special']);

    expect($item->fresh()->version)->toBe(2);
});

it('does not bump the version on a read', function () {
    $item = VersionFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);

    VersionFixtureItem::find($item->id);

    expect($item->fresh()->version)->toBe(1);
});
```

- [ ] **Step 3: Run the tests**

Run: `cd backend && php artisan test --filter=HasVersionTraitTest`
Expected: PASS (3 passed)

- [ ] **Step 4: Commit**

```bash
git add backend/app/Traits/HasVersion.php backend/tests/Unit/HasVersionTraitTest.php
git commit -m "feat: add HasVersion trait for sync-ready row versioning"
```

---

## Task 4: Product model, migration, factory

**Files:**
- Create: `backend/database/migrations/2026_07_15_000001_create_products_table.php`
- Create: `backend/app/Models/Product.php`
- Create: `backend/database/factories/ProductFactory.php`
- Test: `backend/tests/Unit/ProductModelTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_07_15_000001_create_products_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('name_hi', 120);
            $table->string('name_en', 120)->nullable();
            $table->decimal('base_cost_per_kg', 10, 2)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // Every query on this table filters business_id (RLS adds the predicate
            // even when the app layer doesn't), and Postgres does not index foreign
            // keys automatically. pack_sizes/product_packs get this for free from
            // the leftmost column of their composite unique indexes; products has
            // no such index, so it needs an explicit one.
            $table->index('business_id');
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE products ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE products FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY products_isolation ON products
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('products');
    }
};
```

This is the flat policy the tenancy spec reserved for every domain table — no `user_id` branch, unlike `memberships`, because the catalog is only ever read with a tenant already selected. `current_setting(..., true)` returns NULL instead of erroring when the GUC is unset; `NULLIF(..., '')` maps the empty-string case to NULL too, so the predicate is false rather than a cast error.

New tables need no explicit `GRANT`: migration `2026_07_05_000001_create_app_role` ran `ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO vyaparbook_app`, which covers tables created later by the same privileged role.

- [ ] **Step 2: Write the model**

```php
<?php
// app/Models/Product.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, HasVersion;

    // archived_at and version are deliberately absent: they are set by explicit
    // assignment, never mass-assigned. business_id is present because factories
    // fill through $fillable — see the plan's File Structure note.
    protected $fillable = ['business_id', 'name_hi', 'name_en', 'base_cost_per_kg'];

    protected $casts = [
        'base_cost_per_kg' => 'decimal:2',
        'archived_at' => 'datetime',
        'version' => 'integer',
    ];

    public function productPacks(): HasMany
    {
        return $this->hasMany(ProductPack::class);
    }
}
```

- [ ] **Step 3: Write the factory**

```php
<?php
// database/factories/ProductFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name_hi' => $this->faker->randomElement(['सेव', 'सेंव', 'मिक्स', 'भुजिया']),
            // No unique() — products carry no unique constraint, and faker's
            // unique() pool for word() is small enough to overflow in a full run.
            'name_en' => $this->faker->word(),
            'base_cost_per_kg' => $this->faker->randomFloat(2, 50, 300),
        ];
    }
}
```

- [ ] **Step 4: Write a failing test**

```php
<?php
// tests/Unit/ProductModelTest.php

use App\Models\Business;
use App\Models\Product;

it('generates a uuid primary key and starts at version 1', function () {
    $business = Business::factory()->create();

    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'name_hi' => 'सेव',
        'name_en' => 'Sev',
        'base_cost_per_kg' => '120.00',
    ]);

    expect($product->id)->toBeString();
    expect(strlen($product->id))->toBe(36);
    expect($product->fresh()->version)->toBe(1);
});

it('casts money to a 2-decimal string, not a float', function () {
    $business = Business::factory()->create();

    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'name_hi' => 'सेव',
        'base_cost_per_kg' => '120.5',
    ]);

    expect($product->fresh()->base_cost_per_kg)->toBe('120.50');
});
```

`Product::on('pgsql_migrate')` runs as the superuser `postgres`, which bypasses RLS entirely (superusers ignore even `FORCE ROW LEVEL SECURITY`) — the same mechanism `MembershipRlsTest` relies on for setup.

- [ ] **Step 5: Run the migration and tests**

```bash
cd backend
php artisan migrate --database=pgsql_migrate
php artisan test --filter=ProductModelTest
```
Expected: PASS (2 passed)

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations backend/app/Models/Product.php backend/database/factories/ProductFactory.php backend/tests/Unit/ProductModelTest.php
git commit -m "feat: add Product model with RLS isolation policy"
```

---

## Task 5: PackSize model, migration, factory

**Files:**
- Create: `backend/database/migrations/2026_07_15_000002_create_pack_sizes_table.php`
- Create: `backend/app/Models/PackSize.php`
- Create: `backend/database/factories/PackSizeFactory.php`
- Test: `backend/tests/Unit/PackSizeModelTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_07_15_000002_create_pack_sizes_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('pack_sizes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('label', 40);
            $table->decimal('weight_kg', 8, 3);
            $table->boolean('in_dropdown')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // Also serves as the business_id index (leftmost column).
            $table->unique(['business_id', 'label']);
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE pack_sizes ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE pack_sizes FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY pack_sizes_isolation ON pack_sizes
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('pack_sizes');
    }
};
```

`weight_kg` carries 3 decimals so 100g is exactly `0.100`. `label` is free display text; `weight_kg` is the canonical unit that does all arithmetic (spec §2).

- [ ] **Step 2: Write the model**

```php
<?php
// app/Models/PackSize.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackSize extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, HasVersion;

    protected $fillable = ['business_id', 'label', 'weight_kg', 'in_dropdown'];

    protected $casts = [
        'weight_kg' => 'decimal:3',
        'in_dropdown' => 'boolean',
        'archived_at' => 'datetime',
        'version' => 'integer',
    ];

    public function productPacks(): HasMany
    {
        return $this->hasMany(ProductPack::class);
    }
}
```

- [ ] **Step 3: Write the factory**

```php
<?php
// database/factories/PackSizeFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\PackSize;
use Illuminate\Database\Eloquent\Factories\Factory;

class PackSizeFactory extends Factory
{
    protected $model = PackSize::class;

    public function definition(): array
    {
        // Drawn from a wide range rather than a handful of realistic sizes:
        // pack_sizes has a unique (business_id, label) index, and faker's
        // unique() over a 5-element list throws OverflowException as soon as a
        // run needs a sixth. Realism is not what a factory owes here — a label
        // that never collides is.
        $grams = $this->faker->unique()->numberBetween(50, 9999);

        return [
            'business_id' => Business::factory(),
            'label' => $grams . 'g',
            'weight_kg' => number_format($grams / 1000, 3, '.', ''),
            'in_dropdown' => true,
        ];
    }
}
```

- [ ] **Step 4: Write a failing test**

```php
<?php
// tests/Unit/PackSizeModelTest.php

use App\Models\Business;
use App\Models\PackSize;

it('stores 100g as exactly 0.100 kg', function () {
    $business = Business::factory()->create();

    $pack = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'label' => '100g',
        'weight_kg' => '0.100',
    ]);

    expect($pack->fresh()->weight_kg)->toBe('0.100');
});

it('defaults in_dropdown to true', function () {
    $business = Business::factory()->create();

    $pack = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'label' => '500g',
        'weight_kg' => '0.500',
    ]);

    expect($pack->fresh()->in_dropdown)->toBeTrue();
});

it('rejects a duplicate label within the same business', function () {
    $business = Business::factory()->create();

    PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'label' => '500g',
        'weight_kg' => '0.500',
    ]);

    expect(fn () => PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'label' => '500g',
        'weight_kg' => '0.500',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows the same label in a different business', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();

    PackSize::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $second = PackSize::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    expect($second->id)->toBeString();
});
```

- [ ] **Step 5: Run the migration and tests**

```bash
cd backend
php artisan migrate --database=pgsql_migrate
php artisan test --filter=PackSizeModelTest
```
Expected: PASS (4 passed)

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations backend/app/Models/PackSize.php backend/database/factories/PackSizeFactory.php backend/tests/Unit/PackSizeModelTest.php
git commit -m "feat: add PackSize model with RLS isolation policy"
```

---

## Task 6: ProductPack model, migration, factory

**Files:**
- Create: `backend/database/migrations/2026_07_15_000003_create_product_packs_table.php`
- Create: `backend/app/Models/ProductPack.php`
- Create: `backend/database/factories/ProductPackFactory.php`
- Test: `backend/tests/Unit/ProductPackModelTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_07_15_000003_create_product_packs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('product_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('pack_size_id')->constrained('pack_sizes')->cascadeOnDelete();
            $table->decimal('default_sell_price', 10, 2);
            $table->decimal('default_cost_price', 10, 2)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // Also serves as the business_id index (leftmost column).
            $table->unique(['business_id', 'product_id', 'pack_size_id']);
        });

        DB::connection('pgsql_migrate')->statement('ALTER TABLE product_packs ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE product_packs FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY product_packs_isolation ON product_packs
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('product_packs');
    }
};
```

The `cascadeOnDelete` on `product_id`/`pack_size_id` never fires in normal operation — products and packs are archived, never deleted (spec §2). It exists for the one case that does delete rows: dropping a `Business` cascades to everything it owns.

- [ ] **Step 2: Write the model**

```php
<?php
// app/Models/ProductPack.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPack extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, HasVersion;

    protected $fillable = [
        'business_id', 'product_id', 'pack_size_id',
        'default_sell_price', 'default_cost_price',
    ];

    protected $casts = [
        'default_sell_price' => 'decimal:2',
        'default_cost_price' => 'decimal:2',
        'archived_at' => 'datetime',
        'version' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function packSize(): BelongsTo
    {
        return $this->belongsTo(PackSize::class);
    }
}
```

- [ ] **Step 3: Write the factory**

```php
<?php
// database/factories/ProductPackFactory.php

namespace Database\Factories;

use App\Models\Business;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductPackFactory extends Factory
{
    protected $model = ProductPack::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'product_id' => Product::factory(),
            'pack_size_id' => PackSize::factory(),
            'default_sell_price' => $this->faker->randomFloat(2, 10, 500),
            'default_cost_price' => $this->faker->randomFloat(2, 5, 400),
        ];
    }
}
```

Callers must pass `business_id`, `product_id` and `pack_size_id` explicitly when they need them to agree — the defaults above build three *unrelated* businesses. Tests that care always pass all three.

- [ ] **Step 4: Write a failing test**

```php
<?php
// tests/Unit/ProductPackModelTest.php

use App\Models\Business;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;

it('relates a product to a pack size with its own price', function () {
    $business = Business::factory()->create();
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'base_cost_per_kg' => '120.00',
    ]);
    $pack = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    $productPack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'pack_size_id' => $pack->id,
        'default_sell_price' => '80.00',
        'default_cost_price' => '60.00',
    ]);

    $fresh = ProductPack::on('pgsql_migrate')->with(['product', 'packSize'])->find($productPack->id);

    expect($fresh->product->name_hi)->toBe('सेव');
    expect($fresh->packSize->label)->toBe('500g');
    expect($fresh->default_sell_price)->toBe('80.00');
});

it('rejects the same product/pack pairing twice', function () {
    $business = Business::factory()->create();
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव',
    ]);
    $pack = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    $attrs = [
        'business_id' => $business->id,
        'product_id' => $product->id,
        'pack_size_id' => $pack->id,
        'default_sell_price' => '80.00',
    ];

    ProductPack::on('pgsql_migrate')->create($attrs);

    expect(fn () => ProductPack::on('pgsql_migrate')->create($attrs))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 5: Run the migration and tests**

```bash
cd backend
php artisan migrate --database=pgsql_migrate
php artisan test --filter=ProductPackModelTest
```
Expected: PASS (2 passed)

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations backend/app/Models/ProductPack.php backend/database/factories/ProductPackFactory.php backend/tests/Unit/ProductPackModelTest.php
git commit -m "feat: add ProductPack model with RLS isolation policy"
```

---

## Task 7: CatalogService::suggestedCostPrice

**Files:**
- Create: `backend/app/Services/CatalogService.php`
- Test: `backend/tests/Unit/CatalogServiceTest.php`

One rule, one home: "suggest `base_cost_per_kg × weight_kg`" is needed both when a template omits a cost (Task 14) and when someone POSTs a product-pack without one (Task 12). It lives here so the two cannot drift.

- [ ] **Step 1: Write a failing test**

```php
<?php
// tests/Unit/CatalogServiceTest.php

use App\Models\PackSize;
use App\Models\Product;
use App\Services\CatalogService;

function makeProduct(?string $costPerKg): Product
{
    $product = new Product();
    $product->base_cost_per_kg = $costPerKg;

    return $product;
}

function makePack(string $weightKg): PackSize
{
    $pack = new PackSize();
    $pack->weight_kg = $weightKg;

    return $pack;
}

it('suggests cost proportional to pack weight', function () {
    $suggested = (new CatalogService())->suggestedCostPrice(
        makeProduct('120.00'),
        makePack('0.500')
    );

    expect($suggested)->toBe('60.00');
});

it('handles a 100g pack without float drift', function () {
    $suggested = (new CatalogService())->suggestedCostPrice(
        makeProduct('120.00'),
        makePack('0.100')
    );

    expect($suggested)->toBe('12.00');
});

it('truncates rather than rounds a fractional paisa', function () {
    // 133.33 × 0.100 = 13.333 → 13.33. This is a suggestion the tenant can
    // overwrite, so truncation is acceptable and — unlike rounding up — can
    // never overstate cost.
    $suggested = (new CatalogService())->suggestedCostPrice(
        makeProduct('133.33'),
        makePack('0.100')
    );

    expect($suggested)->toBe('13.33');
});

it('returns null when the product has no base cost', function () {
    $suggested = (new CatalogService())->suggestedCostPrice(
        makeProduct(null),
        makePack('0.500')
    );

    expect($suggested)->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=CatalogServiceTest`
Expected: FAIL — `Class "App\Services\CatalogService" not found`

- [ ] **Step 3: Write the service**

```php
<?php
// app/Services/CatalogService.php

namespace App\Services;

use App\Models\PackSize;
use App\Models\Product;

class CatalogService
{
    /**
     * Suggest a pack's cost from the product's per-kg base cost.
     *
     * Only ever fills a blank at creation time — default_cost_price is stored
     * per pack and authoritative once set, because packaging and labour do not
     * scale linearly with weight (a 100g pouch genuinely costs more per kg than
     * a 1kg bag). See the catalog spec §5.
     *
     * bcmul, not float arithmetic: rupees must not drift. It truncates at the
     * given scale rather than rounding, which cannot overstate a cost.
     */
    public function suggestedCostPrice(Product $product, PackSize $pack): ?string
    {
        if ($product->base_cost_per_kg === null) {
            return null;
        }

        return bcmul((string) $product->base_cost_per_kg, (string) $pack->weight_kg, 2);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter=CatalogServiceTest`
Expected: PASS (4 passed)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/CatalogService.php backend/tests/Unit/CatalogServiceTest.php
git commit -m "feat: add CatalogService::suggestedCostPrice with exact decimal math"
```

---

## Task 8: CatalogPolicy

**Files:**
- Create: `backend/app/Policies/CatalogPolicy.php`
- Test: covered by Task 10's RBAC test (this task ships the class the controllers call)

- [ ] **Step 1: Write the policy**

Mirrors `InvitePolicy` exactly — same shape, same container-read of the verified role.

```php
<?php
// app/Policies/CatalogPolicy.php

namespace App\Policies;

class CatalogPolicy
{
    /**
     * PRD §7, "Manage catalog & prices": owner and admin only.
     *
     * Reads are deliberately not gated — a salesman cannot sell without the
     * catalog and an accountant needs it to read a khata. The role comes from
     * the membership SetTenantContext verified, never from the client.
     */
    public function manage(): bool
    {
        return in_array(app('tenant.role'), ['owner', 'admin'], true);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add backend/app/Policies/CatalogPolicy.php
git commit -m "feat: add CatalogPolicy gating catalog writes to owner/admin"
```

---

## Task 9: Product CRUD + archive/restore

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/ProductController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Catalog/CatalogCrudTest.php`

- [ ] **Step 1: Write the controller**

```php
<?php
// app/Http/Controllers/Api/V1/ProductController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Policies\CatalogPolicy;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function store(Request $request)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $data = $request->validate([
            'name_hi' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'base_cost_per_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        // business_id is stamped by BelongsToTenant from app('tenant.id') — never
        // taken from the request, which is why it is not in the validated set.
        $product = Product::create($data);

        return response()->json($product, 201);
    }

    public function update(Request $request, string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        // findOrFail, not a manual tenant check: RLS has already hidden other
        // tenants' rows, so this raises a genuine 404 rather than a 403 that
        // would confirm the row exists. See the catalog spec §6.
        $product = Product::findOrFail($id);

        $data = $request->validate([
            'name_hi' => ['sometimes', 'required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'base_cost_per_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product->update($data);

        return response()->json($product->fresh());
    }

    public function destroy(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $product = Product::findOrFail($id);

        // Archive, never delete: §9's ledger is append-only, so a two-year-old
        // sale must still resolve what was sold. archived_at is not fillable, so
        // it is assigned directly.
        $product->archived_at = Carbon::now();
        $product->save();

        return response()->json(['message' => 'Archived.']);
    }

    public function restore(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $product = Product::findOrFail($id);
        $product->archived_at = null;
        $product->save();

        return response()->json($product->fresh());
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'Only owners and admins can manage the catalog.'],
            403
        );
    }
}
```

- [ ] **Step 2: Add the routes**

In `backend/routes/api.php`, inside the existing `Route::middleware(['require.tenant'])->group(...)` block (alongside the invite route):

```php
            Route::post('products', [\App\Http\Controllers\Api\V1\ProductController::class, 'store']);
            Route::patch('products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'update']);
            Route::delete('products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'destroy']);
            Route::post('products/{id}/restore', [\App\Http\Controllers\Api\V1\ProductController::class, 'restore']);
```

- [ ] **Step 3: Write a failing feature test**

```php
<?php
// tests/Feature/Catalog/CatalogCrudTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Product;
use App\Models\User;
use App\Services\TokenService;

function ownerToken(Business $business): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('creates a product stamped with the caller tenant', function () {
    $business = Business::factory()->create();
    $token = ownerToken($business);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', [
            'name_hi' => 'सेव',
            'name_en' => 'Sev',
            'base_cost_per_kg' => '120.00',
        ])
        ->assertStatus(201)
        ->assertJson(['name_hi' => 'सेव', 'name_en' => 'Sev']);

    $created = Product::on('pgsql_migrate')->find($response->json('id'));
    expect($created->business_id)->toBe($business->id);
});

it('updates a product and bumps its version', function () {
    $business = Business::factory()->create();
    $token = ownerToken($business);
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}", ['name_en' => 'Sev Special'])
        ->assertOk()
        ->assertJson(['name_en' => 'Sev Special']);

    expect(Product::on('pgsql_migrate')->find($product->id)->version)->toBe(2);
});

it('rejects a product with no hindi name', function () {
    $business = Business::factory()->create();
    $token = ownerToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', ['name_en' => 'Sev'])
        ->assertStatus(422);
});

it('returns 404 for a product in another business', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $token = ownerToken($mine);

    $foreign = Product::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'name_hi' => 'हल्दी',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$foreign->id}", ['name_en' => 'Haldi'])
        ->assertStatus(404);
});
```

- [ ] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter=CatalogCrudTest`
Expected: PASS (4 passed)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/ProductController.php backend/routes/api.php backend/tests/Feature/Catalog/CatalogCrudTest.php
git commit -m "feat: add product CRUD endpoints with archive semantics"
```

---

## Task 10: RBAC coverage for catalog writes

**Files:**
- Test: `backend/tests/Feature/Catalog/CatalogRbacTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/Catalog/CatalogRbacTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;

function tokenForRole(Business $business, string $role): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('lets an owner create a product', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . tokenForRole($business, 'owner'))
        ->postJson('/api/v1/products', ['name_hi' => 'सेव'])
        ->assertStatus(201);
});

it('lets an admin create a product', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . tokenForRole($business, 'admin'))
        ->postJson('/api/v1/products', ['name_hi' => 'सेव'])
        ->assertStatus(201);
});

it('blocks a salesman from creating a product', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . tokenForRole($business, 'salesman'))
        ->postJson('/api/v1/products', ['name_hi' => 'सेव'])
        ->assertStatus(403);
});

it('blocks an accountant from creating a product', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . tokenForRole($business, 'accountant'))
        ->postJson('/api/v1/products', ['name_hi' => 'सेव'])
        ->assertStatus(403);
});
```

- [ ] **Step 2: Run the tests**

Run: `cd backend && php artisan test --filter=CatalogRbacTest`
Expected: PASS (4 passed)

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Feature/Catalog/CatalogRbacTest.php
git commit -m "test: cover catalog write RBAC across all four roles"
```

---

## Task 11: PackSize CRUD + archive/restore

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/PackSizeController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Catalog/PackSizeCrudTest.php`

- [ ] **Step 1: Write the controller**

```php
<?php
// app/Http/Controllers/Api/V1/PackSizeController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PackSize;
use App\Policies\CatalogPolicy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PackSizeController extends Controller
{
    public function store(Request $request)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $data = $request->validate([
            // No tenant clause on the unique rule, and none is needed: this query
            // runs inside SetTenantContext's transaction with app.current_tenant
            // set, so RLS has already narrowed pack_sizes to this business. It
            // looks like a missing scope; it is not.
            'label' => ['required', 'string', 'max:40', Rule::unique('pack_sizes', 'label')],
            'weight_kg' => ['required', 'numeric', 'gt:0'],
            'in_dropdown' => ['nullable', 'boolean'],
        ], [
            // An archived label still occupies the unique index. Restoring is the
            // right move, so say so rather than reporting a bare "already taken".
            'label.unique' => 'That pack size already exists. If it is archived, restore it instead.',
        ]);

        $packSize = PackSize::create($data);

        return response()->json($packSize, 201);
    }

    public function update(Request $request, string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $packSize = PackSize::findOrFail($id);

        $data = $request->validate([
            'label' => [
                'sometimes', 'required', 'string', 'max:40',
                Rule::unique('pack_sizes', 'label')->ignore($packSize->id),
            ],
            'weight_kg' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'in_dropdown' => ['nullable', 'boolean'],
        ]);

        $packSize->update($data);

        return response()->json($packSize->fresh());
    }

    public function destroy(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $packSize = PackSize::findOrFail($id);
        $packSize->archived_at = Carbon::now();
        $packSize->save();

        return response()->json(['message' => 'Archived.']);
    }

    public function restore(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $packSize = PackSize::findOrFail($id);
        $packSize->archived_at = null;
        $packSize->save();

        return response()->json($packSize->fresh());
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'Only owners and admins can manage the catalog.'],
            403
        );
    }
}
```

- [ ] **Step 2: Add the routes**

Inside the same `require.tenant` group in `backend/routes/api.php`:

```php
            Route::post('pack-sizes', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'store']);
            Route::patch('pack-sizes/{id}', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'update']);
            Route::delete('pack-sizes/{id}', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'destroy']);
            Route::post('pack-sizes/{id}/restore', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'restore']);
```

- [ ] **Step 3: Write a failing feature test**

```php
<?php
// tests/Feature/Catalog/PackSizeCrudTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\User;
use App\Services\TokenService;

function packOwnerToken(Business $business): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('creates a pack size', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . packOwnerToken($business))
        ->postJson('/api/v1/pack-sizes', ['label' => '500g', 'weight_kg' => '0.500'])
        ->assertStatus(201)
        ->assertJson(['label' => '500g', 'in_dropdown' => true]);
});

it('rejects a duplicate label in the same business', function () {
    $business = Business::factory()->create();
    PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    $this->withHeader('Authorization', 'Bearer ' . packOwnerToken($business))
        ->postJson('/api/v1/pack-sizes', ['label' => '500g', 'weight_kg' => '0.500'])
        ->assertStatus(422)
        ->assertJsonPath('errors.label.0', 'That pack size already exists. If it is archived, restore it instead.');
});

it('allows the same label in a different business', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    PackSize::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    // The unique rule is tenant-scoped by RLS alone — another business holding
    // this label must not block us.
    $this->withHeader('Authorization', 'Bearer ' . packOwnerToken($mine))
        ->postJson('/api/v1/pack-sizes', ['label' => '500g', 'weight_kg' => '0.500'])
        ->assertStatus(201);
});

it('rejects a zero or negative weight', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . packOwnerToken($business))
        ->postJson('/api/v1/pack-sizes', ['label' => '0g', 'weight_kg' => '0'])
        ->assertStatus(422);
});
```

The third test is the one that matters most — it proves the unscoped `Rule::unique` is genuinely tenant-scoped by RLS rather than accidentally global.

- [ ] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter=PackSizeCrudTest`
Expected: PASS (4 passed)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/PackSizeController.php backend/routes/api.php backend/tests/Feature/Catalog/PackSizeCrudTest.php
git commit -m "feat: add pack size CRUD endpoints"
```

---

## Task 12: ProductPack CRUD + archive/restore

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/ProductPackController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Catalog/ProductPackCrudTest.php`

- [ ] **Step 1: Write the controller**

```php
<?php
// app/Http/Controllers/Api/V1/ProductPackController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Policies\CatalogPolicy;
use App\Services\CatalogService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProductPackController extends Controller
{
    public function __construct(private readonly CatalogService $catalogService) {}

    public function store(Request $request)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $data = $request->validate([
            // exists checks run under RLS, so an id from another business simply
            // does not exist here and fails validation with a 422 — it can never
            // pair one tenant's product with another tenant's pack.
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'pack_size_id' => ['required', 'uuid', 'exists:pack_sizes,id'],
            'default_sell_price' => ['required', 'numeric', 'min:0'],
            'default_cost_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $packSize = PackSize::findOrFail($data['pack_size_id']);

        $productPack = ProductPack::create([
            'product_id' => $product->id,
            'pack_size_id' => $packSize->id,
            'default_sell_price' => $data['default_sell_price'],
            // Suggest from the per-kg base cost only when the caller left it
            // blank. Once set, the per-pack figure is authoritative.
            'default_cost_price' => $data['default_cost_price']
                ?? $this->catalogService->suggestedCostPrice($product, $packSize),
        ]);

        return response()->json($productPack, 201);
    }

    public function update(Request $request, string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $productPack = ProductPack::findOrFail($id);

        $data = $request->validate([
            'default_sell_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'default_cost_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $productPack->update($data);

        return response()->json($productPack->fresh());
    }

    public function destroy(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $productPack = ProductPack::findOrFail($id);
        $productPack->archived_at = Carbon::now();
        $productPack->save();

        return response()->json(['message' => 'Archived.']);
    }

    public function restore(string $id)
    {
        if (! (new CatalogPolicy())->manage()) {
            return $this->denied();
        }

        $productPack = ProductPack::findOrFail($id);
        $productPack->archived_at = null;
        $productPack->save();

        return response()->json($productPack->fresh());
    }

    private function denied()
    {
        return response()->json(
            ['message' => 'Only owners and admins can manage the catalog.'],
            403
        );
    }
}
```

- [ ] **Step 2: Add the routes**

Inside the same `require.tenant` group in `backend/routes/api.php`:

```php
            Route::post('product-packs', [\App\Http\Controllers\Api\V1\ProductPackController::class, 'store']);
            Route::patch('product-packs/{id}', [\App\Http\Controllers\Api\V1\ProductPackController::class, 'update']);
            Route::delete('product-packs/{id}', [\App\Http\Controllers\Api\V1\ProductPackController::class, 'destroy']);
            Route::post('product-packs/{id}/restore', [\App\Http\Controllers\Api\V1\ProductPackController::class, 'restore']);
```

- [ ] **Step 3: Write a failing feature test**

```php
<?php
// tests/Feature/Catalog/ProductPackCrudTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\User;
use App\Services\TokenService;

function ppOwnerToken(Business $business): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('fills the cost price from the per-kg base cost when omitted', function () {
    $business = Business::factory()->create();
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'base_cost_per_kg' => '120.00',
    ]);
    $pack = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    $this->withHeader('Authorization', 'Bearer ' . ppOwnerToken($business))
        ->postJson('/api/v1/product-packs', [
            'product_id' => $product->id,
            'pack_size_id' => $pack->id,
            'default_sell_price' => '80.00',
        ])
        ->assertStatus(201)
        ->assertJson(['default_cost_price' => '60.00']);
});

it('keeps an explicit cost price instead of the suggestion', function () {
    $business = Business::factory()->create();
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'base_cost_per_kg' => '120.00',
    ]);
    $pack = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '100g', 'weight_kg' => '0.100',
    ]);

    $this->withHeader('Authorization', 'Bearer ' . ppOwnerToken($business))
        ->postJson('/api/v1/product-packs', [
            'product_id' => $product->id,
            'pack_size_id' => $pack->id,
            'default_sell_price' => '20.00',
            'default_cost_price' => '15.00', // packaging costs more per kg on a small pouch
        ])
        ->assertStatus(201)
        ->assertJson(['default_cost_price' => '15.00']);
});

it('refuses to pair a product with another business pack size', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $mine->id, 'name_hi' => 'सेव',
    ]);
    $foreignPack = PackSize::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);

    $this->withHeader('Authorization', 'Bearer ' . ppOwnerToken($mine))
        ->postJson('/api/v1/product-packs', [
            'product_id' => $product->id,
            'pack_size_id' => $foreignPack->id,
            'default_sell_price' => '80.00',
        ])
        ->assertStatus(422);
});
```

- [ ] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter=ProductPackCrudTest`
Expected: PASS (3 passed)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/ProductPackController.php backend/routes/api.php backend/tests/Feature/Catalog/ProductPackCrudTest.php
git commit -m "feat: add product pack CRUD with suggested cost price"
```

---

## Task 13: GET /catalog aggregate read with effective archiving

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/CatalogController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Catalog/CatalogReadTest.php`
- Test: `backend/tests/Feature/Catalog/CatalogArchiveTest.php`

`GET /catalog` is one payload the PWA caches in a single round trip (PRD §9's cache-first design, on a 2G link).

**Effective archiving (spec §2):** a `ProductPack` is hidden when it, its `Product`, **or** its `PackSize` is archived — evaluated at read time. Archiving a product never writes `archived_at` onto its packs. Cascading the write would destroy information and make restore ambiguous: after un-archiving Sev, a pack archived individually beforehand must stay archived, and only read-time evaluation preserves that.

- [ ] **Step 1: Write the controller**

```php
<?php
// app/Http/Controllers/Api/V1/CatalogController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PackSize;
use App\Models\Product;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    /**
     * The whole tenant catalog in one response. Readable by every role: a
     * salesman cannot sell without it and an accountant needs it to read a khata.
     */
    public function index(Request $request)
    {
        $includeArchived = $request->boolean('include_archived');

        $products = Product::query()
            ->unless($includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->with(['productPacks' => function ($q) use ($includeArchived) {
                $q->with('packSize');

                // Effective archiving: hide a pack whose own row, or whose pack
                // size, is archived. The product's own state is already handled
                // by the outer query.
                $q->unless($includeArchived, function ($q) {
                    $q->whereNull('product_packs.archived_at')
                        ->whereHas('packSize', fn ($p) => $p->whereNull('pack_sizes.archived_at'));
                });
            }])
            ->orderBy('name_hi')
            ->get();

        $packSizes = PackSize::query()
            ->unless($includeArchived, fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('weight_kg')
            ->get();

        return response()->json([
            // name_hi/name_en are returned raw rather than pre-resolved to a
            // display string: which one to show is the client's decision, driven
            // by the business's language, falling back to name_hi when name_en is
            // absent. Choosing server-side would bake a language into the API.
            'products' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'name_hi' => $product->name_hi,
                'name_en' => $product->name_en,
                'base_cost_per_kg' => $product->base_cost_per_kg,
                'archived_at' => $product->archived_at,
                'version' => $product->version,
                'packs' => $product->productPacks->map(fn ($pack) => [
                    'id' => $pack->id,
                    'pack_size_id' => $pack->pack_size_id,
                    'label' => $pack->packSize->label,
                    'weight_kg' => $pack->packSize->weight_kg,
                    'in_dropdown' => $pack->packSize->in_dropdown,
                    'default_sell_price' => $pack->default_sell_price,
                    'default_cost_price' => $pack->default_cost_price,
                    'archived_at' => $pack->archived_at,
                    'version' => $pack->version,
                ])->values(),
            ])->values(),

            // Pack sizes with in_dropdown = false ARE included. The flag is a
            // rendering hint the client applies to the sale screen's dropdown,
            // not a filter on the payload — those sizes are still sellable and
            // the offline cache must hold them.
            'pack_sizes' => $packSizes->map(fn (PackSize $packSize) => [
                'id' => $packSize->id,
                'label' => $packSize->label,
                'weight_kg' => $packSize->weight_kg,
                'in_dropdown' => $packSize->in_dropdown,
                'archived_at' => $packSize->archived_at,
                'version' => $packSize->version,
            ])->values(),
        ]);
    }
}
```

- [ ] **Step 2: Add the route**

Inside the same `require.tenant` group in `backend/routes/api.php`:

```php
            Route::get('catalog', [\App\Http\Controllers\Api\V1\CatalogController::class, 'index']);
```

- [ ] **Step 3: Write a failing read test**

```php
<?php
// tests/Feature/Catalog/CatalogReadTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Services\TokenService;

function readToken(Business $business, string $role = 'owner'): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

function seedOneProductPack(Business $business, array $packAttrs = []): array
{
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
        'base_cost_per_kg' => '120.00',
    ]);
    $packSize = PackSize::on('pgsql_migrate')->create(array_merge([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ], $packAttrs));
    $productPack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'pack_size_id' => $packSize->id,
        'default_sell_price' => '80.00',
        'default_cost_price' => '60.00',
    ]);

    return [$product, $packSize, $productPack];
}

it('returns products with their packs and prices nested', function () {
    $business = Business::factory()->create();
    seedOneProductPack($business);

    $this->withHeader('Authorization', 'Bearer ' . readToken($business))
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonPath('products.0.name_hi', 'सेव')
        ->assertJsonPath('products.0.packs.0.label', '500g')
        ->assertJsonPath('products.0.packs.0.default_sell_price', '80.00')
        ->assertJsonPath('pack_sizes.0.label', '500g');
});

it('lets a salesman read the catalog', function () {
    $business = Business::factory()->create();
    seedOneProductPack($business);

    $this->withHeader('Authorization', 'Bearer ' . readToken($business, 'salesman'))
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonPath('products.0.name_hi', 'सेव');
});

it('includes pack sizes that are hidden from the dropdown', function () {
    $business = Business::factory()->create();
    seedOneProductPack($business, ['in_dropdown' => false]);

    $this->withHeader('Authorization', 'Bearer ' . readToken($business))
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonPath('pack_sizes.0.in_dropdown', false)
        ->assertJsonCount(1, 'pack_sizes');
});

it('never returns another business catalog', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    seedOneProductPack($theirs);

    $this->withHeader('Authorization', 'Bearer ' . readToken($mine))
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(0, 'products')
        ->assertJsonCount(0, 'pack_sizes');
});
```

- [ ] **Step 4: Write a failing archive test**

```php
<?php
// tests/Feature/Catalog/CatalogArchiveTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Services\TokenService;

function archiveToken(Business $business): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return (new TokenService())->issue($user, $membership);
}

function archiveFixture(Business $business): array
{
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'base_cost_per_kg' => '120.00',
    ]);
    $packSize = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $productPack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'pack_size_id' => $packSize->id,
        'default_sell_price' => '80.00',
    ]);

    return [$product, $packSize, $productPack];
}

it('drops an archived product from the catalog', function () {
    $business = Business::factory()->create();
    [$product] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(0, 'products');
});

it('returns an archived product under include_archived', function () {
    $business = Business::factory()->create();
    [$product] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}");

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog?include_archived=1')
        ->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonPath('products.0.id', $product->id);
});

it('keeps an archived product resolvable by id so old sales still work', function () {
    $business = Business::factory()->create();
    [$product] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}");

    expect(Product::on('pgsql_migrate')->find($product->id))->not->toBeNull();
});

it('restores an archived product', function () {
    $business = Business::factory()->create();
    [$product] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}");

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/restore")
        ->assertOk()
        ->assertJson(['archived_at' => null]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertJsonCount(1, 'products');
});

it('hides a product packs without writing archived_at on them', function () {
    $business = Business::factory()->create();
    [$product, , $productPack] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}");

    // The pack vanishes from the read...
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertJsonCount(0, 'products');

    // ...but its own row is untouched. This is what makes restore lossless.
    expect(ProductPack::on('pgsql_migrate')->find($productPack->id)->archived_at)->toBeNull();
});

it('restores only the packs that were not individually archived', function () {
    $business = Business::factory()->create();
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव',
    ]);
    $keep = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $drop = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '1kg', 'weight_kg' => '1.000',
    ]);
    $keptPack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $keep->id, 'default_sell_price' => '80.00',
    ]);
    $archivedPack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $drop->id, 'default_sell_price' => '150.00',
    ]);
    $token = archiveToken($business);

    // Archive one pack individually, then the whole product, then restore it.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/product-packs/{$archivedPack->id}")->assertOk();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}")->assertOk();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/products/{$product->id}/restore")->assertOk();

    // The individually-archived pack must stay gone — the information survived.
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonCount(1, 'products.0.packs');

    expect($response->json('products.0.packs.0.id'))->toBe($keptPack->id);
});

it('hides a pack whose pack size is archived', function () {
    $business = Business::factory()->create();
    [, $packSize] = archiveFixture($business);
    $token = archiveToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/pack-sizes/{$packSize->id}")
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonCount(0, 'products.0.packs')
        ->assertJsonCount(0, 'pack_sizes');
});
```

- [ ] **Step 5: Run the tests**

```bash
cd backend
php artisan test --filter=CatalogReadTest
php artisan test --filter=CatalogArchiveTest
```
Expected: PASS (4 passed, then 7 passed)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/CatalogController.php backend/routes/api.php backend/tests/Feature/Catalog/CatalogReadTest.php backend/tests/Feature/Catalog/CatalogArchiveTest.php
git commit -m "feat: add GET /catalog aggregate read with read-time archiving"
```

---

## Task 14: Catalog templates + CatalogTemplateService

**Files:**
- Create: `backend/database/catalog_templates/namkeen.php`
- Create: `backend/database/catalog_templates/sweets.php`
- Create: `backend/database/catalog_templates/spices.php`
- Create: `backend/app/Services/CatalogTemplateService.php`
- Test: `backend/tests/Unit/CatalogTemplateServiceTest.php`

Templates are code: versioned in git, reviewed in PRs, and a new Phase 3 vertical is a new file with no migration. Rows land in the tenant's own tables, so "every tenant edits freely" (PRD §6) needs no extra machinery.

- [ ] **Step 1: Write the namkeen template**

Tenant #1 (Shree Raj Shyama Ji) is the Namkeen template — Senvda/Sev/Mix.

**Note on the pack-size count:** PRD §5 says tenant #1 has 15 pack sizes. This template ships **8**, because the real 15 are that tenant's actual data and we do not have the list. Eight covers the common range, and every row is editable the instant it is seeded (PRD §6) — a template is a fast start, not a fixture. Importing tenant #1's real sizes belongs to the Excel-import slice. The prices below are plausible placeholders for the same reason. If you have the real list, use it and update the counts asserted in Step 5 and Task 15.

```php
<?php
// database/catalog_templates/namkeen.php
//
// Keys ('sev', '500g') are template-local identifiers resolved to UUIDs at
// insert time. Nothing depends on display text, so renaming a product here
// never breaks a product_packs reference below.

return [
    'label' => 'Namkeen / Snacks',

    'products' => [
        'senvda' => ['name_hi' => 'सेंवड़ा', 'name_en' => 'Senvda', 'base_cost_per_kg' => '120.00'],
        'sev' => ['name_hi' => 'सेव', 'name_en' => 'Sev', 'base_cost_per_kg' => '130.00'],
        'mix' => ['name_hi' => 'मिक्स', 'name_en' => 'Mix', 'base_cost_per_kg' => '140.00'],
    ],

    'pack_sizes' => [
        '50g' => ['label' => '50g', 'weight_kg' => '0.050', 'in_dropdown' => false],
        '100g' => ['label' => '100g', 'weight_kg' => '0.100', 'in_dropdown' => true],
        '200g' => ['label' => '200g', 'weight_kg' => '0.200', 'in_dropdown' => true],
        '250g' => ['label' => '250g', 'weight_kg' => '0.250', 'in_dropdown' => true],
        '500g' => ['label' => '500g', 'weight_kg' => '0.500', 'in_dropdown' => true],
        '1kg' => ['label' => '1kg', 'weight_kg' => '1.000', 'in_dropdown' => true],
        '5kg' => ['label' => '5kg', 'weight_kg' => '5.000', 'in_dropdown' => true],
        '10kg' => ['label' => '10kg', 'weight_kg' => '10.000', 'in_dropdown' => false],
    ],

    // default_cost_price is omitted throughout: CatalogTemplateService fills it
    // from base_cost_per_kg × weight_kg via CatalogService::suggestedCostPrice.
    'product_packs' => [
        ['product' => 'senvda', 'pack' => '100g', 'default_sell_price' => '20.00'],
        ['product' => 'senvda', 'pack' => '200g', 'default_sell_price' => '38.00'],
        ['product' => 'senvda', 'pack' => '500g', 'default_sell_price' => '90.00'],
        ['product' => 'senvda', 'pack' => '1kg', 'default_sell_price' => '175.00'],
        ['product' => 'sev', 'pack' => '100g', 'default_sell_price' => '22.00'],
        ['product' => 'sev', 'pack' => '250g', 'default_sell_price' => '52.00'],
        ['product' => 'sev', 'pack' => '500g', 'default_sell_price' => '98.00'],
        ['product' => 'sev', 'pack' => '1kg', 'default_sell_price' => '190.00'],
        ['product' => 'mix', 'pack' => '100g', 'default_sell_price' => '24.00'],
        ['product' => 'mix', 'pack' => '250g', 'default_sell_price' => '58.00'],
        ['product' => 'mix', 'pack' => '500g', 'default_sell_price' => '110.00'],
        ['product' => 'mix', 'pack' => '1kg', 'default_sell_price' => '210.00'],
    ],
];
```

- [ ] **Step 2: Write the sweets template**

```php
<?php
// database/catalog_templates/sweets.php

return [
    'label' => 'Sweets / Mithai',

    'products' => [
        'laddu' => ['name_hi' => 'लड्डू', 'name_en' => 'Laddu', 'base_cost_per_kg' => '260.00'],
        'barfi' => ['name_hi' => 'बर्फी', 'name_en' => 'Barfi', 'base_cost_per_kg' => '300.00'],
        'peda' => ['name_hi' => 'पेड़ा', 'name_en' => 'Peda', 'base_cost_per_kg' => '280.00'],
    ],

    'pack_sizes' => [
        '250g' => ['label' => '250g', 'weight_kg' => '0.250', 'in_dropdown' => true],
        '500g' => ['label' => '500g', 'weight_kg' => '0.500', 'in_dropdown' => true],
        '1kg' => ['label' => '1kg', 'weight_kg' => '1.000', 'in_dropdown' => true],
        '2kg' => ['label' => '2kg', 'weight_kg' => '2.000', 'in_dropdown' => false],
    ],

    'product_packs' => [
        ['product' => 'laddu', 'pack' => '250g', 'default_sell_price' => '75.00'],
        ['product' => 'laddu', 'pack' => '500g', 'default_sell_price' => '145.00'],
        ['product' => 'laddu', 'pack' => '1kg', 'default_sell_price' => '280.00'],
        ['product' => 'barfi', 'pack' => '250g', 'default_sell_price' => '85.00'],
        ['product' => 'barfi', 'pack' => '500g', 'default_sell_price' => '165.00'],
        ['product' => 'barfi', 'pack' => '1kg', 'default_sell_price' => '320.00'],
        ['product' => 'peda', 'pack' => '250g', 'default_sell_price' => '80.00'],
        ['product' => 'peda', 'pack' => '500g', 'default_sell_price' => '155.00'],
        ['product' => 'peda', 'pack' => '1kg', 'default_sell_price' => '300.00'],
    ],
];
```

- [ ] **Step 3: Write the spices template**

```php
<?php
// database/catalog_templates/spices.php

return [
    'label' => 'Spices / Masala',

    'products' => [
        'haldi' => ['name_hi' => 'हल्दी', 'name_en' => 'Haldi', 'base_cost_per_kg' => '180.00'],
        'mirch' => ['name_hi' => 'मिर्च', 'name_en' => 'Mirch', 'base_cost_per_kg' => '240.00'],
        'dhaniya' => ['name_hi' => 'धनिया', 'name_en' => 'Dhaniya', 'base_cost_per_kg' => '160.00'],
    ],

    'pack_sizes' => [
        '50g' => ['label' => '50g', 'weight_kg' => '0.050', 'in_dropdown' => true],
        '100g' => ['label' => '100g', 'weight_kg' => '0.100', 'in_dropdown' => true],
        '200g' => ['label' => '200g', 'weight_kg' => '0.200', 'in_dropdown' => true],
        '500g' => ['label' => '500g', 'weight_kg' => '0.500', 'in_dropdown' => true],
    ],

    'product_packs' => [
        ['product' => 'haldi', 'pack' => '50g', 'default_sell_price' => '12.00'],
        ['product' => 'haldi', 'pack' => '100g', 'default_sell_price' => '22.00'],
        ['product' => 'haldi', 'pack' => '200g', 'default_sell_price' => '42.00'],
        ['product' => 'haldi', 'pack' => '500g', 'default_sell_price' => '100.00'],
        ['product' => 'mirch', 'pack' => '50g', 'default_sell_price' => '15.00'],
        ['product' => 'mirch', 'pack' => '100g', 'default_sell_price' => '28.00'],
        ['product' => 'mirch', 'pack' => '200g', 'default_sell_price' => '54.00'],
        ['product' => 'dhaniya', 'pack' => '100g', 'default_sell_price' => '20.00'],
        ['product' => 'dhaniya', 'pack' => '200g', 'default_sell_price' => '38.00'],
    ],
];
```

- [ ] **Step 4: Write the service**

```php
<?php
// app/Services/CatalogTemplateService.php

namespace App\Services;

use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use InvalidArgumentException;

class CatalogTemplateService
{
    /**
     * "Blank" is a first-class choice from PRD §5's onboarding step, not the
     * absence of one: it has no file and inserts nothing.
     */
    public const BLANK = 'blank';

    public function __construct(private readonly CatalogService $catalogService) {}

    /**
     * Template slugs a caller may pick, derived from the files on disk so adding
     * a vertical never means editing a hardcoded list.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $files = glob(database_path('catalog_templates/*.php')) ?: [];

        return array_merge(
            [self::BLANK],
            array_map(fn (string $path) => basename($path, '.php'), $files)
        );
    }

    /**
     * Insert a template's rows for one business.
     *
     * Runs inside the caller's transaction — SetTenantContext has already opened
     * one and set app.current_tenant, so BelongsToTenant stamps business_id and
     * the RLS WITH CHECK passes. business_id is still passed explicitly here so
     * the service is callable from a console command or seeder that has no
     * request context.
     */
    public function apply(string $slug, string $businessId): void
    {
        if ($slug === self::BLANK) {
            return;
        }

        $path = database_path("catalog_templates/{$slug}.php");

        if (! file_exists($path)) {
            throw new InvalidArgumentException("Unknown catalog template [{$slug}].");
        }

        $template = require $path;

        $products = [];
        foreach ($template['products'] as $key => $attributes) {
            $products[$key] = Product::create($attributes + ['business_id' => $businessId]);
        }

        $packSizes = [];
        foreach ($template['pack_sizes'] as $key => $attributes) {
            $packSizes[$key] = PackSize::create($attributes + ['business_id' => $businessId]);
        }

        foreach ($template['product_packs'] as $row) {
            $product = $products[$row['product']];
            $packSize = $packSizes[$row['pack']];

            ProductPack::create([
                'business_id' => $businessId,
                'product_id' => $product->id,
                'pack_size_id' => $packSize->id,
                'default_sell_price' => $row['default_sell_price'],
                'default_cost_price' => $row['default_cost_price']
                    ?? $this->catalogService->suggestedCostPrice($product, $packSize),
            ]);
        }
    }
}
```

- [ ] **Step 5: Write a failing test**

```php
<?php
// tests/Unit/CatalogTemplateServiceTest.php

use App\Models\Business;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Services\CatalogTemplateService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

it('lists the templates on disk plus blank', function () {
    $available = CatalogTemplateService::available();

    expect($available)->toContain('blank', 'namkeen', 'sweets', 'spices');
});

it('seeds the namkeen template into one business', function () {
    $business = Business::factory()->create();

    // Mirror what a request does: a transaction with the tenant GUC set, so the
    // RLS WITH CHECK admits the inserts.
    DB::transaction(function () use ($business) {
        TenantContext::switchTo($business->id);
        app()->bind('tenant.id', fn () => $business->id);

        app(CatalogTemplateService::class)->apply('namkeen', $business->id);
    });

    expect(Product::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(3);
    expect(PackSize::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(8);
    expect(ProductPack::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(12);
});

it('fills each pack cost from the product base cost per kg', function () {
    $business = Business::factory()->create();

    DB::transaction(function () use ($business) {
        TenantContext::switchTo($business->id);
        app()->bind('tenant.id', fn () => $business->id);

        app(CatalogTemplateService::class)->apply('namkeen', $business->id);
    });

    $sev = Product::on('pgsql_migrate')
        ->where('business_id', $business->id)->where('name_en', 'Sev')->first();
    $oneKg = PackSize::on('pgsql_migrate')
        ->where('business_id', $business->id)->where('label', '1kg')->first();
    $pack = ProductPack::on('pgsql_migrate')
        ->where('product_id', $sev->id)->where('pack_size_id', $oneKg->id)->first();

    // Sev base_cost_per_kg 130.00 × 1.000 kg
    expect($pack->default_cost_price)->toBe('130.00');
});

it('inserts nothing for the blank template', function () {
    $business = Business::factory()->create();

    DB::transaction(function () use ($business) {
        TenantContext::switchTo($business->id);
        app()->bind('tenant.id', fn () => $business->id);

        app(CatalogTemplateService::class)->apply('blank', $business->id);
    });

    expect(Product::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(0);
});

it('rejects an unknown template', function () {
    $business = Business::factory()->create();

    expect(fn () => app(CatalogTemplateService::class)->apply('nonexistent', $business->id))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 6: Run the tests**

Run: `cd backend && php artisan test --filter=CatalogTemplateServiceTest`
Expected: PASS (5 passed)

- [ ] **Step 7: Commit**

```bash
git add backend/database/catalog_templates backend/app/Services/CatalogTemplateService.php backend/tests/Unit/CatalogTemplateServiceTest.php
git commit -m "feat: add catalog templates for namkeen, sweets and spices"
```

---

## Task 15: POST /catalog/seed endpoint

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/CatalogController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Catalog/CatalogTemplateTest.php`

- [ ] **Step 1: Add the seed method to CatalogController**

Add these imports to `backend/app/Http/Controllers/Api/V1/CatalogController.php`:

```php
use App\Policies\CatalogPolicy;
use App\Services\CatalogTemplateService;
use Illuminate\Validation\Rule;
```

Add the constructor and method to the class:

```php
    public function __construct(private readonly CatalogTemplateService $templateService) {}

    public function seed(Request $request)
    {
        if (! (new CatalogPolicy())->manage()) {
            return response()->json(
                ['message' => 'Only owners and admins can manage the catalog.'],
                403
            );
        }

        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(CatalogTemplateService::available())],
        ]);

        // Seeding is guarded, not idempotent. Without this, a second seed hits
        // the pack_sizes unique index and surfaces a raw constraint violation as
        // a 500. PRD §5 frames this as a one-time onboarding step; a 409 states
        // that rule, a duplicate-key crash does not.
        if (Product::query()->exists()) {
            return response()->json([
                'message' => 'Catalog is not empty. Seeding is a one-time onboarding step.',
            ], 409);
        }

        $this->templateService->apply($data['template'], app('tenant.id'));

        return response()->json(['message' => 'Catalog seeded.'], 201);
    }
```

- [ ] **Step 2: Add the route**

Inside the same `require.tenant` group in `backend/routes/api.php`:

```php
            Route::post('catalog/seed', [\App\Http\Controllers\Api\V1\CatalogController::class, 'seed']);
```

- [ ] **Step 3: Write a failing feature test**

```php
<?php
// tests/Feature/Catalog/CatalogTemplateTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Product;
use App\Models\User;
use App\Services\TokenService;

function seedToken(Business $business, string $role = 'owner'): string
{
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

it('seeds the namkeen template for the caller business', function () {
    $business = Business::factory()->create();
    $token = seedToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/catalog/seed', ['template' => 'namkeen'])
        ->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(3, 'products')
        ->assertJsonCount(8, 'pack_sizes');
});

it('leaves seeded rows freely editable by the tenant', function () {
    $business = Business::factory()->create();
    $token = seedToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/catalog/seed', ['template' => 'namkeen']);

    $product = Product::on('pgsql_migrate')->where('business_id', $business->id)->first();

    // A seeded row is an ordinary tenant row — PRD §6's "every tenant edits freely".
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}", ['name_en' => 'My Own Name'])
        ->assertOk()
        ->assertJson(['name_en' => 'My Own Name']);
});

it('refuses to seed a catalog that already has products', function () {
    $business = Business::factory()->create();
    $token = seedToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/catalog/seed', ['template' => 'namkeen'])
        ->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/catalog/seed', ['template' => 'sweets'])
        ->assertStatus(409);
});

it('accepts blank as a no-op', function () {
    $business = Business::factory()->create();
    $token = seedToken($business);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/catalog/seed', ['template' => 'blank'])
        ->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertJsonCount(0, 'products');
});

it('rejects an unknown template name', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . seedToken($business))
        ->postJson('/api/v1/catalog/seed', ['template' => 'nonexistent'])
        ->assertStatus(422);
});

it('blocks a salesman from seeding', function () {
    $business = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . seedToken($business, 'salesman'))
        ->postJson('/api/v1/catalog/seed', ['template' => 'namkeen'])
        ->assertStatus(403);
});

it('seeds only the caller business, never a neighbour', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    $this->withHeader('Authorization', 'Bearer ' . seedToken($mine))
        ->postJson('/api/v1/catalog/seed', ['template' => 'namkeen'])
        ->assertStatus(201);

    expect(Product::on('pgsql_migrate')->where('business_id', $theirs->id)->count())->toBe(0);
});
```

- [ ] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter=CatalogTemplateTest`
Expected: PASS (7 passed)

- [ ] **Step 5: Verify the whole onboarding flow end to end**

This is the PRD §5 path a real owner walks. Run it against the dev server to confirm the token from `POST /businesses` can seed immediately with no extra switch.

```bash
cd backend
php artisan serve &
SERVER_PID=$!
sleep 2

TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/v1/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"name":"Raj","email":"raj@example.com","password":"secret123"}' | php -r 'echo json_decode(file_get_contents("php://stdin"))->token;')

BIZ_TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/v1/businesses \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"Shree Raj Shyama Ji","city":"Jaipur"}' | php -r 'echo json_decode(file_get_contents("php://stdin"))->token;')

curl -s -X POST http://127.0.0.1:8000/api/v1/catalog/seed \
  -H "Authorization: Bearer $BIZ_TOKEN" -H 'Content-Type: application/json' \
  -d '{"template":"namkeen"}'
echo

curl -s http://127.0.0.1:8000/api/v1/catalog -H "Authorization: Bearer $BIZ_TOKEN"
echo

kill $SERVER_PID
```
Expected: the seed call returns `{"message":"Catalog seeded."}` and the catalog call returns 3 products with nested packs. If `POST /businesses` needed a separate switch first, this is where that shows up.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/CatalogController.php backend/routes/api.php backend/tests/Feature/Catalog/CatalogTemplateTest.php
git commit -m "feat: add POST /catalog/seed with one-time onboarding guard"
```

---

## Task 16: DB-level RLS proof

**Files:**
- Test: `backend/tests/Feature/Tenancy/CatalogRlsTest.php`

Mirrors `MembershipRlsTest`: proves the policies themselves, with the app layer removed. Uses the query builder rather than Eloquent deliberately — `BelongsToTenant`'s global scope would otherwise mask whether RLS is doing anything, which is the whole point of this file.

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/Tenancy/CatalogRlsTest.php

use App\Models\Business;
use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('hides another business products even with the app layer bypassed', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    Product::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'name_hi' => 'हल्दी',
    ]);

    DB::transaction(function () use ($mine) {
        TenantContext::switchTo($mine->id);

        // Raw query builder: no Eloquent, no global scope. Anything returned
        // here got past RLS itself.
        $visible = DB::table('products')->count();

        expect($visible)->toBe(0);
    });
});

it('blocks inserting a product for a business other than the current tenant', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    expect(function () use ($mine, $theirs) {
        DB::transaction(function () use ($mine, $theirs) {
            TenantContext::switchTo($mine->id);

            DB::table('products')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $theirs->id, // mismatched on purpose
                'name_hi' => 'चोरी',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});

it('blocks inserting a pack size for another tenant', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    expect(function () use ($mine, $theirs) {
        DB::transaction(function () use ($mine, $theirs) {
            TenantContext::switchTo($mine->id);

            DB::table('pack_sizes')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $theirs->id,
                'label' => '500g',
                'weight_kg' => '0.500',
                'in_dropdown' => true,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});

it('shows a business its own products', function () {
    $mine = Business::factory()->create();

    Product::on('pgsql_migrate')->create([
        'business_id' => $mine->id, 'name_hi' => 'सेव',
    ]);

    DB::transaction(function () use ($mine) {
        TenantContext::switchTo($mine->id);

        expect(DB::table('products')->count())->toBe(1);
    });
});
```

- [ ] **Step 2: Run the tests**

Run: `cd backend && php artisan test --filter=CatalogRlsTest`
Expected: PASS (4 passed)

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Feature/Tenancy/CatalogRlsTest.php
git commit -m "test: prove catalog RLS policies with the app layer bypassed"
```

---

## Task 17: Catalog cases in the cross-tenant leak suite

**Files:**
- Modify: `backend/tests/Feature/Tenancy/CrossTenantLeakTest.php`

These go in the **existing** suite rather than a new file: it is the single place isolation is proven, and splitting it makes it easy to believe coverage exists where it does not.

- [ ] **Step 1: Append the catalog cases**

Add these imports at the top of `backend/tests/Feature/Tenancy/CrossTenantLeakTest.php` if not already present:

```php
use App\Models\Product;
```

Append:

```php
it('never returns business Bs catalog to business A', function () {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $userA = User::factory()->create();
    $membershipA = Membership::on('pgsql_migrate')->create([
        'user_id' => $userA->id, 'business_id' => $businessA->id, 'role' => 'owner',
    ]);

    Product::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'name_hi' => 'हल्दी', 'name_en' => 'Haldi',
    ]);

    $token = (new TokenService())->issue($userA, $membershipA);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(0, 'products');
});

it('rejects business As owner reading business Bs product by id', function () {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $userA = User::factory()->create();
    $membershipA = Membership::on('pgsql_migrate')->create([
        'user_id' => $userA->id, 'business_id' => $businessA->id, 'role' => 'owner',
    ]);

    $foreign = Product::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'name_hi' => 'हल्दी',
    ]);

    $token = (new TokenService())->issue($userA, $membershipA);

    // 404, not 403: a 403 would confirm the row exists, leaking that a
    // competitor's product id is real.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$foreign->id}", ['name_en' => 'Stolen'])
        ->assertStatus(404);
});

it('rejects business As owner archiving business Bs product', function () {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $userA = User::factory()->create();
    $membershipA = Membership::on('pgsql_migrate')->create([
        'user_id' => $userA->id, 'business_id' => $businessA->id, 'role' => 'owner',
    ]);

    $foreign = Product::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'name_hi' => 'हल्दी',
    ]);

    $token = (new TokenService())->issue($userA, $membershipA);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$foreign->id}")
        ->assertStatus(404);

    // And it really is untouched.
    expect(Product::on('pgsql_migrate')->find($foreign->id)->archived_at)->toBeNull();
});
```

- [ ] **Step 2: Run the suite**

Run: `cd backend && php artisan test --filter=CrossTenantLeakTest`
Expected: PASS (8 passed — 5 pre-existing + 3 new)

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Feature/Tenancy/CrossTenantLeakTest.php
git commit -m "test: extend cross-tenant leak suite with catalog cases"
```

---

## Task 18: Full suite, docs, plan close-out

**Files:**
- Modify: `backend/README.md`
- Modify: `docs/superpowers/plans/2026-07-15-tenant-catalog.md`

- [ ] **Step 1: Run the entire suite**

Run: `cd backend && php artisan test`
Expected: PASS. Baseline before this slice was 40 passed; every task above adds tests and none should have been removed. If anything fails, fix it before continuing — do not mark this task complete with a red suite.

- [ ] **Step 2: Document the catalog API in the README**

Append to `backend/README.md`:

```markdown
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

- **A cross-tenant row returns 404, not 403.** RLS hides other tenants' rows, so
  `findOrFail` genuinely finds nothing. This also avoids confirming that another
  tenant's id is real.
- **`Rule::unique('pack_sizes', 'label')` has no tenant clause.** Validation runs
  inside the request transaction with `app.current_tenant` set, so RLS has already
  narrowed the table to one business.

Archiving is evaluated at read time and never cascaded: a product pack is hidden
when it, its product, or its pack size is archived, but archiving a product does
not write `archived_at` onto its packs. This keeps restore lossless.
```

- [ ] **Step 3: Mark the plan complete**

Tick every checkbox in this plan file and add a "Known Gaps" section recording anything deferred during execution (matching the tenancy plan's close-out convention). If nothing was deferred, say so explicitly rather than omitting the section.

- [ ] **Step 4: Commit**

```bash
git add backend/README.md docs/superpowers/plans/2026-07-15-tenant-catalog.md
git commit -m "docs: document the catalog API and close out the plan"
```

---

## Self-Review Notes

**Spec coverage** — every section of `2026-07-15-catalog-design.md` maps to a task:

| Spec section | Tasks |
|---|---|
| §2 Data model (3 tables, decimals, names, flags) | 4, 5, 6 |
| §2 Archiving never cascaded | 13 |
| §3 Isolation (RLS + BelongsToTenant + HasVersion) | 3, 4, 5, 6, 16 |
| §4 Templates (files, service, 409 guard, blank) | 14, 15 |
| §5 Endpoints & RBAC | 8, 9, 10, 11, 12, 13, 15 |
| §5 One rule one home (`suggestedCostPrice`) | 7, 12, 14 |
| §6 Error handling (400/403/404/409/422) | 9, 10, 11, 12, 15, 17 |
| §7 Testing (every named file) | 3, 7, 9–17 |
| §8 Decisions | reflected throughout; rationale comments in code |
| §9 Open questions (both deferred) | no task needed — recorded as accepted |

**Additions beyond the spec, and why:** Tasks 1 and 2 fix pre-existing container/queue gaps. Neither is a catalog feature, but the catalog is `BelongsToTenant`'s first consumer, so both go from latent to fatal the moment Task 4 lands — Task 1 in particular blocks every catalog test. Both were verified against the running app (tinker for the binding throw; reading `TenantAwareJob` for the missing bind), not inferred.

**Deviations from the spec, called out rather than silent:**

- Spec §2 says reads "fall back to `name_hi`". `GET /catalog` returns `name_hi` and `name_en` raw and leaves the fallback to the client (Task 13). Resolving server-side would bake a language choice into the API before any frontend exists to state a preference. The spec's intent — never store a stale copy — is preserved.
- Spec §4's template sketch shows "…15 sizes for tenant #1"; the namkeen template ships 8 placeholder sizes with plausible prices, because tenant #1's real list is data we do not have. Flagged in Task 14 with the counts to update if it arrives.

**Known risk the plan cannot remove:** the whole suite still runs against Postgres directly — PgBouncer is not configured (see the tenancy plan's Known Gaps and `backend/README.md`). Every RLS and `SET LOCAL` guarantee these tasks test is therefore proven against Postgres semantics, not against transaction pooling in situ. That is unchanged by this slice and does not block it, but a green suite here is not evidence that pooling is safe.

**Type/name consistency:** `CatalogPolicy::manage()`, `CatalogService::suggestedCostPrice(Product, PackSize): ?string`, `CatalogTemplateService::apply(string, string): void`, `CatalogTemplateService::available(): array`, `CatalogTemplateService::BLANK`, and the `archived_at` / `version` / `in_dropdown` column names are used identically in every task that references them. All three models expose `productPacks()`; `ProductPack` exposes `product()` and `packSize()`, matching the eager loads in Task 13.

**Test count:** the suite should finish at 40 (baseline) + 1 + 1 + 3 + 2 + 4 + 2 + 4 + 4 + 4 + 4 + 3 + 4 + 7 + 5 + 7 + 4 + 3 = **102 passing**. A materially lower number means tasks were skipped.
