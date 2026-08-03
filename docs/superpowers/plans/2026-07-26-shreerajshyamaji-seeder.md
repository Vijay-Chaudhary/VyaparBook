# Shree Raj Shyama Ji Namkeen Seeder Implementation Plan

> **Historical (pre-2026-07-30).** This document predates the PostgreSQL → MySQL 8
> migration; its RLS / `SET LOCAL` / PgBouncer references describe the system as it
> was then, not as it runs now. See
> `docs/superpowers/specs/2026-07-30-postgres-to-mysql-design.md`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the two invented demo tenants with the real April–June 2026 records of Shree Raj Shyama Ji Namkeen, and extend the app's unit and expense-category vocabularies so that data seeds faithfully.

**Architecture:** Business records live in `database/seed_data/shreerajshyamaji/*.php` — one file per master, returning a plain array, following the existing `database/catalog_templates/*.php` convention. `ShreeRajShyamajiSeeder` walks those files and holds no business data itself. Two small value classes (`MaterialUnit`, `ExpenseCategory`) become the single source of truth for the vocabularies they define.

**Tech Stack:** PHP 8.3, Laravel, PostgreSQL (RLS), Pest.

**Spec:** `docs/superpowers/specs/2026-07-26-shreerajshyamaji-seeder-design.md`

---

## Essential background

Read this before Task 1. It is the non-obvious part of the codebase.

**Seeders write on the `pgsql_migrate` connection, never the default one.** A seeder
runs outside a request, so no `SetTenantContext` transaction has set
`app.current_tenant`. On the app connection, the RLS `WITH CHECK` predicate
(`business_id = current_setting('app.current_tenant')`) rejects every
tenant-owned insert. `pgsql_migrate` is the schema-owning role and bypasses RLS.
The app-layer `BelongsToTenant` global scope is a no-op here because
`app('tenant.id')` resolves to null, so explicit `business_id` values pass
through. Every model write in this plan uses `Model::on('pgsql_migrate')` or
`$model->setConnection('pgsql_migrate')`.

**Guarded columns are set by assignment, not mass assignment.** `created_by`,
`Sale::total` and `SaleLine::line_total` are deliberately absent from
`$fillable` — the writer services stamp them in production. In the seeder, use
`new Model([...])`, then assign the guarded property, then `save()`.

**Stock on-hand is `Σ stock_movements.qty`, not a column.** `StockService::onHandFor`
sums the movements. So a `Purchase` row alone does not raise stock — production
code (`PurchaseWriter`) also writes a positive `in` `StockMovement` tagged with
`purchase_id`. Likewise `ProductionWriter` writes a **negative** `out` movement
per `MaterialConsumption`, tagged with `production_batch_id`. **The seeder must
write both halves or every material reads zero on-hand.** Movement `qty` is
signed: `in` positive, `out` negative.

**Product cost comes from production, not from purchases directly.**
`CogsService` computes `material ₹/kg` from purchases, `batch cost` from
consumptions, then `product ₹/kg = Σ batch cost ÷ Σ output_kg`. This is why the
production data is reconstructed rather than copied — see the spec.

---

### Task 1: `MaterialUnit` value class

Extracts the raw-material unit list, currently copy-pasted into two classes, and
adds the three units the real data needs (`bag`, `dozen`, `tina`).

**Files:**
- Create: `backend/app/Stock/MaterialUnit.php`
- Test: `backend/tests/Unit/MaterialUnitTest.php`

- [x] **Step 1: Write the failing test**

Create `backend/tests/Unit/MaterialUnitTest.php`:

```php
<?php
// tests/Unit/MaterialUnitTest.php

use App\Stock\MaterialUnit;

it('exposes the canonical unit keys in order', function () {
    expect(MaterialUnit::keys())->toBe([
        'kg', 'gram', 'litre', 'ml', 'piece', 'packet', 'bag', 'dozen', 'tina',
    ]);
});

it('validates membership', function () {
    expect(MaterialUnit::isValid('kg'))->toBeTrue();
    expect(MaterialUnit::isValid('tina'))->toBeTrue();
    expect(MaterialUnit::isValid('furlong'))->toBeFalse();
    expect(MaterialUnit::isValid(''))->toBeFalse();
});

it('keeps ml, which predates this list', function () {
    // Removing it would orphan any existing row already stored as ml.
    expect(MaterialUnit::isValid('ml'))->toBeTrue();
});
```

- [x] **Step 2: Run test to verify it fails**

```bash
cd backend && ./vendor/bin/pest tests/Unit/MaterialUnitTest.php
```

Expected: FAIL — `Class "App\Stock\MaterialUnit" not found`.

- [x] **Step 3: Write minimal implementation**

Create `backend/app/Stock/MaterialUnit.php`:

```php
<?php
// app/Stock/MaterialUnit.php

namespace App\Stock;

/**
 * Single source of truth for raw-material units. The API validator and the CSV
 * importer both read this list, so it is defined exactly once — it was
 * previously copy-pasted into RawMaterialController and TenantImporter, which
 * is how the two could have drifted apart.
 *
 * `tina` is the sealed tin oil is bought and billed in (~15 kg). `bag` and
 * `dozen` are likewise how suppliers invoice, not conveniences: a shop that
 * cannot record the unit on the invoice ends up converting by hand, which is
 * where the arithmetic errors come from.
 */
final class MaterialUnit
{
    /** @return list<string> canonical order, used everywhere the list renders. */
    public static function keys(): array
    {
        return ['kg', 'gram', 'litre', 'ml', 'piece', 'packet', 'bag', 'dozen', 'tina'];
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
```

- [x] **Step 4: Run test to verify it passes**

```bash
cd backend && ./vendor/bin/pest tests/Unit/MaterialUnitTest.php
```

Expected: PASS, 3 tests.

- [x] **Step 5: Commit**

```bash
cd backend && git add app/Stock/MaterialUnit.php tests/Unit/MaterialUnitTest.php
git commit -m "feat: add MaterialUnit as the single home for raw-material units"
```

---

### Task 2: Point the controller and importer at `MaterialUnit`

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/RawMaterialController.php:20`
- Modify: `backend/app/Import/TenantImporter.php:34`
- Test: `backend/tests/Feature/Stock/RawMaterialCrudTest.php`

- [x] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Stock/RawMaterialCrudTest.php`:

This file authenticates with a JWT via the existing `materialToken()` helper
defined at its top, not `actingAs` — match that pattern:

```php
it('accepts the units suppliers actually bill in', function () {
    $business = Business::factory()->create();
    $token = materialToken($business);

    // Oil is bought by the sealed tin. Without this unit the shop has to
    // convert to litres by hand before it can record its largest spend.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/raw-materials', ['name' => 'Refined Oil', 'unit' => 'tina'])
        ->assertCreated()
        ->assertJson(['name' => 'Refined Oil', 'unit' => 'tina']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/raw-materials', ['name' => 'Cement', 'unit' => 'bag'])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/raw-materials', ['name' => 'Eggs', 'unit' => 'dozen'])
        ->assertCreated();
});
```

- [x] **Step 2: Run test to verify it fails**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Stock/RawMaterialCrudTest.php --filter="units suppliers actually bill"
```

Expected: FAIL — 422 validation error, because `tina` is not in the controller's
hardcoded list.

- [x] **Step 3: Write minimal implementation**

In `backend/app/Http/Controllers/Api/V1/RawMaterialController.php`, delete the
`private const UNITS = [...];` line and add the import:

```php
use App\Stock\MaterialUnit;
```

Then replace both validation references (there are two — `store` and `update`):

```php
'unit' => ['required', Rule::in(MaterialUnit::keys())],
```

```php
'unit' => ['sometimes', 'required', Rule::in(MaterialUnit::keys())],
```

In `backend/app/Import/TenantImporter.php`, delete
`private const UNITS = [...];`, add `use App\Stock\MaterialUnit;`, and replace
the check:

```php
if (! MaterialUnit::isValid($unit)) {
    $report->addError($i, 'unit must be one of: ' . implode(', ', MaterialUnit::keys()));
    continue;
}
```

- [x] **Step 4: Run tests to verify they pass**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Stock/ tests/Unit/MaterialUnitTest.php tests/Feature/Import/
```

Expected: PASS. The existing `rejects a material with no name or a bad unit`
test uses `furlong`, which is still invalid, so it should stay green.

- [x] **Step 5: Commit**

```bash
cd backend && git add app/Http/Controllers/Api/V1/RawMaterialController.php app/Import/TenantImporter.php tests/Feature/Stock/RawMaterialCrudTest.php
git commit -m "refactor: read the unit list from MaterialUnit in both call sites"
```

---

### Task 3: Extend `ExpenseCategory` with diesel and packing material

**Files:**
- Modify: `backend/app/Expenses/ExpenseCategory.php:22`
- Modify: `backend/lang/en/expenses.php`
- Modify: `backend/lang/hi/expenses.php`
- Test: `backend/tests/Unit/ExpenseCategoryTest.php`

- [x] **Step 1: Update the failing test**

`backend/tests/Unit/ExpenseCategoryTest.php` pins the exact key list, so this is
a deliberate edit, not a break. Replace the first test and add a label test:

```php
<?php
// tests/Unit/ExpenseCategoryTest.php

use App\Expenses\ExpenseCategory;

it('exposes the canonical category keys in order', function () {
    expect(ExpenseCategory::keys())->toBe([
        'rent', 'salaries', 'electricity', 'diesel', 'transport',
        'packing_material', 'maintenance', 'other',
    ]);
});

it('validates membership', function () {
    expect(ExpenseCategory::isValid('rent'))->toBeTrue();
    expect(ExpenseCategory::isValid('diesel'))->toBeTrue();
    expect(ExpenseCategory::isValid('groceries'))->toBeFalse();
    expect(ExpenseCategory::isValid(''))->toBeFalse();
});

it('knows which categories require a note', function () {
    expect(ExpenseCategory::requiresNote('other'))->toBeTrue();
    expect(ExpenseCategory::requiresNote('rent'))->toBeFalse();
});

it('has a label for every key in both languages', function () {
    // A missing Hindi label is invisible until someone switches language, by
    // which point it renders as the raw translation key at a shopkeeper.
    foreach (['en', 'hi'] as $locale) {
        $labels = require base_path("lang/{$locale}/expenses.php");

        foreach (ExpenseCategory::keys() as $key) {
            expect($labels['categories'][$key] ?? null)
                ->not->toBeNull("missing {$locale} label for '{$key}'");
        }
    }
});
```

- [x] **Step 2: Run test to verify it fails**

```bash
cd backend && ./vendor/bin/pest tests/Unit/ExpenseCategoryTest.php
```

Expected: FAIL — the key list does not yet contain `diesel` or
`packing_material`, and the label test reports both missing in each locale.

- [x] **Step 3: Write minimal implementation**

In `backend/app/Expenses/ExpenseCategory.php`, replace the `keys()` body:

```php
    /** @return list<string> canonical order, used everywhere the list renders. */
    public static function keys(): array
    {
        return [
            'rent', 'salaries', 'electricity', 'diesel', 'transport',
            'packing_material', 'maintenance', 'other',
        ];
    }
```

In `backend/lang/en/expenses.php`, replace the `categories` array:

```php
    'categories' => [
        'rent' => 'Rent',
        'salaries' => 'Salaries / Wages',
        'electricity' => 'Electricity',
        'diesel' => 'Diesel',
        'transport' => 'Transport / Fuel',
        'packing_material' => 'Packing Material',
        'maintenance' => 'Maintenance',
        'other' => 'Other',
    ],
```

In `backend/lang/hi/expenses.php`, replace the `categories` array:

```php
    'categories' => [
        'rent' => 'किराया',
        'salaries' => 'वेतन / मज़दूरी',
        'electricity' => 'बिजली',
        'diesel' => 'डीज़ल',
        'transport' => 'परिवहन / ईंधन',
        'packing_material' => 'पैकिंग सामग्री',
        'maintenance' => 'रखरखाव',
        'other' => 'अन्य',
    ],
```

- [x] **Step 4: Run tests to verify they pass**

```bash
cd backend && ./vendor/bin/pest tests/Unit/ExpenseCategoryTest.php tests/Feature/Web/ExpensesTest.php tests/Feature/Web/ReportsDashboardTest.php
```

Expected: PASS. The dashboard breakdown iterates `ExpenseCategory::keys()`, so
it picks up both new rows automatically.

- [x] **Step 5: Commit**

```bash
cd backend && git add app/Expenses/ExpenseCategory.php lang/en/expenses.php lang/hi/expenses.php tests/Unit/ExpenseCategoryTest.php
git commit -m "feat: add diesel and packing-material expense categories"
```

---

### Task 4: Catalog data file

Pure data, no test of its own — Task 7 asserts against it.

**Files:**
- Create: `backend/database/seed_data/shreerajshyamaji/catalog.php`

- [x] **Step 1: Create the file**

`base_cost_per_kg` matches the reconstructed recipe cost in the spec, so
`CatalogService::suggestedCostPrice` derives sensible pack costs. `in_dropdown`
is true only for the ten sizes actually sold.

```php
<?php
// database/seed_data/shreerajshyamaji/catalog.php
//
// Keys ('senvda', '800g') are file-local identifiers resolved to UUIDs at
// insert time, exactly as database/catalog_templates/*.php does.
//
// default_sell_price is the MODAL rate across the owner's real sale lines, not
// an average: it is the price most customers actually pay, and it is only a
// default — every seeded line carries the rate that customer was charged.

return [
    'products' => [
        'senvda'  => ['name_hi' => 'सेंवड़ा',   'name_en' => 'Senvda',  'base_cost_per_kg' => '62.08'],
        'sev'     => ['name_hi' => 'सेव',      'name_en' => 'Sev',     'base_cost_per_kg' => '73.13'],
        'mix_sev' => ['name_hi' => 'मिक्स सेव', 'name_en' => 'Mix Sev', 'base_cost_per_kg' => '87.32'],
    ],

    // The owner's 15-size master, plus 250g and 375g which appear in sales but
    // were missing from it. in_dropdown false = a size they do not currently
    // sell, kept so the list matches their master without cluttering the order
    // screen.
    'pack_sizes' => [
        '250g'  => ['label' => '250g',  'weight_kg' => '0.250', 'in_dropdown' => true],
        '300g'  => ['label' => '300g',  'weight_kg' => '0.300', 'in_dropdown' => true],
        '350g'  => ['label' => '350g',  'weight_kg' => '0.350', 'in_dropdown' => true],
        '375g'  => ['label' => '375g',  'weight_kg' => '0.375', 'in_dropdown' => true],
        '400g'  => ['label' => '400g',  'weight_kg' => '0.400', 'in_dropdown' => true],
        '450g'  => ['label' => '450g',  'weight_kg' => '0.450', 'in_dropdown' => false],
        '500g'  => ['label' => '500g',  'weight_kg' => '0.500', 'in_dropdown' => false],
        '550g'  => ['label' => '550g',  'weight_kg' => '0.550', 'in_dropdown' => false],
        '600g'  => ['label' => '600g',  'weight_kg' => '0.600', 'in_dropdown' => false],
        '650g'  => ['label' => '650g',  'weight_kg' => '0.650', 'in_dropdown' => false],
        '700g'  => ['label' => '700g',  'weight_kg' => '0.700', 'in_dropdown' => true],
        '750g'  => ['label' => '750g',  'weight_kg' => '0.750', 'in_dropdown' => false],
        '800g'  => ['label' => '800g',  'weight_kg' => '0.800', 'in_dropdown' => true],
        '850g'  => ['label' => '850g',  'weight_kg' => '0.850', 'in_dropdown' => false],
        '900g'  => ['label' => '900g',  'weight_kg' => '0.900', 'in_dropdown' => true],
        '950g'  => ['label' => '950g',  'weight_kg' => '0.950', 'in_dropdown' => true],
        '1kg'   => ['label' => '1kg',   'weight_kg' => '1.000', 'in_dropdown' => true],
    ],

    // 21 packs — one per product/size combination the owner has actually sold.
    'product_packs' => [
        ['product' => 'senvda',  'pack' => '300g', 'default_sell_price' => '30.00'],
        ['product' => 'senvda',  'pack' => '350g', 'default_sell_price' => '35.00'],
        ['product' => 'senvda',  'pack' => '375g', 'default_sell_price' => '35.00'],
        ['product' => 'senvda',  'pack' => '400g', 'default_sell_price' => '36.00'],
        ['product' => 'senvda',  'pack' => '700g', 'default_sell_price' => '70.00'],
        ['product' => 'senvda',  'pack' => '800g', 'default_sell_price' => '74.00'],
        ['product' => 'senvda',  'pack' => '900g', 'default_sell_price' => '85.00'],
        ['product' => 'senvda',  'pack' => '1kg',  'default_sell_price' => '100.00'],
        ['product' => 'sev',     'pack' => '350g', 'default_sell_price' => '38.00'],
        ['product' => 'sev',     'pack' => '400g', 'default_sell_price' => '44.00'],
        ['product' => 'sev',     'pack' => '800g', 'default_sell_price' => '88.00'],
        ['product' => 'sev',     'pack' => '900g', 'default_sell_price' => '106.00'],
        ['product' => 'sev',     'pack' => '950g', 'default_sell_price' => '105.00'],
        ['product' => 'sev',     'pack' => '1kg',  'default_sell_price' => '110.00'],
        ['product' => 'mix_sev', 'pack' => '250g', 'default_sell_price' => '32.00'],
        ['product' => 'mix_sev', 'pack' => '300g', 'default_sell_price' => '39.00'],
        ['product' => 'mix_sev', 'pack' => '350g', 'default_sell_price' => '43.00'],
        ['product' => 'mix_sev', 'pack' => '400g', 'default_sell_price' => '48.00'],
        ['product' => 'mix_sev', 'pack' => '800g', 'default_sell_price' => '105.00'],
        ['product' => 'mix_sev', 'pack' => '900g', 'default_sell_price' => '120.00'],
        ['product' => 'mix_sev', 'pack' => '1kg',  'default_sell_price' => '120.00'],
    ],
];
```

- [x] **Step 2: Verify it parses and has the expected shape**

```bash
cd backend && php -r '$c = require "database/seed_data/shreerajshyamaji/catalog.php";
printf("products=%d pack_sizes=%d product_packs=%d\n", count($c["products"]), count($c["pack_sizes"]), count($c["product_packs"]));'
```

Expected: `products=3 pack_sizes=17 product_packs=21`

- [x] **Step 3: Commit**

```bash
cd backend && git add database/seed_data/shreerajshyamaji/catalog.php
git commit -m "data: add the Shree Raj Shyama Ji catalog"
```

---

### Task 5: Master data files

**Files:**
- Create: `backend/database/seed_data/shreerajshyamaji/customers.php`
- Create: `backend/database/seed_data/shreerajshyamaji/suppliers.php`
- Create: `backend/database/seed_data/shreerajshyamaji/materials.php`

- [x] **Step 1: Create `customers.php`**

```php
<?php
// database/seed_data/shreerajshyamaji/customers.php
//
// [name, village]. Opening balance is 0.00 for everyone: every balance derives
// from the seeded sales and payments, never stored (PRD §9).
//
// Phone is deliberately absent — the owner supplied none. ReminderService
// therefore blocks every customer as `no_phone`, which is the honest state.
//
// Two names repeat across villages (Santosh Singh in Aziz and Harpur; Vikash ji
// in Asna and Lohepar). They are different people; the UI shows village beneath
// the name, so they stay distinguishable.
//
// The last five appear only in transactions, not in the owner's master list.
// Confirmed as genuine customers, not typos of the names above them.

return [
    ['Manish ji', 'Hata'],
    ['Byash ji', 'Bhaisahi'],
    ['Vikash ji', 'Asna'],
    ['Mishra ji', 'Tinahawan'],
    ['Raju', 'Harpur'],
    ['Chotte lal', 'Mathauli'],
    ['Rajan', 'Pattan'],
    ['Rajan Ke Chana', 'Pattan'],
    ['Santosh Singh', 'Aziz'],
    ['Munna', 'Bankatawan'],
    ['Vishnu', 'Ragarganj'],
    ['Amit ji', 'Jhingrahiyan'],
    ['Santosh Singh', 'Harpur'],
    ['Bajarangi', 'Mathauli'],
    ['Guppta ji', 'Nanhu mudera'],
    ['Richa Bakers', 'Aziz'],
    ['Yadav ji', 'Aziz'],
    ['Dilip ji', 'Aziz'],
    ['Ache lal', 'Satbhariyan'],
    ['Krishna ji', 'Sohsa'],
    ['Girja Sankar', 'Sohsa'],
    ['Golu ji', 'Hata'],
    ['Anarudh', 'Bhiswan'],
    ['Sharma ji', 'Gaderi pati'],
    ['Ajay Singh', 'Ahirauli'],
    ['Vikash ji', 'Lohepar'],
    ['Munna Singh', 'Ahirauli thana'],
    ['Bhim ji', 'Mathauli'],
    ['Dharmendra ji', 'Khaurantanwa'],
    ['Ashish', 'Ragar ganj'],
    ['Gurudev ji', 'Jhanga'],
    ['Sahil ji', 'Laxmipur'],
    ['Star ji', 'Mathauli'],
    ['Vinod gupta', 'Lohepar'],
    ['Santosh Jaysawal', 'parsauni'],
    ['Dwivedi ji', 'Aziz'],
    ['Ghore lal', 'Mathauli'],
    ['Madhav', 'Ragarganj'],
    ['Munna Singh', 'Nandu Mundera'],
    ['Parthiv', 'Khairatwa'],
];
```

- [x] **Step 2: Create `suppliers.php`**

```php
<?php
// database/seed_data/shreerajshyamaji/suppliers.php
//
// Opening balance 0.00. No supplier payments were supplied, so the whole
// Rs 3,42,305 of purchases seeds as outstanding payable.

return [
    'Kamakhya GKP',
    'Floar Mill Hata',
    'Balaji Trader Hata',
    'PPP (Panni) Shambu GKP',
    'PPP (Bora) Shambu GKP',
    'LDO Supplier',
];
```

- [x] **Step 3: Create `materials.php`**

```php
<?php
// database/seed_data/shreerajshyamaji/materials.php
//
// [name, unit, reorder_level]. Opening stock is 0.00 for all, as the owner's
// sheet states; on-hand accrues from the seeded stock movements.
//
// Black Salt: the owner's master lists it in Packet, but the purchase records
// 50.00 Kg at Rs 25 -- the same rate as White Salt. Seeded as kg.
//
// Masur Dal and Spices Mix are in the master with no purchases and no
// consumption. They seed at zero on-hand, below reorder, which is correct: they
// are exactly the materials the low-stock report should be shouting about.

return [
    ['Besan (Gram Flour)', 'kg', '100.000'],
    ['Refined Oil', 'tina', '10.000'],
    ['Masur Dal', 'kg', '50.000'],
    ['Peanuts', 'kg', '30.000'],
    ['Chawal Anta', 'kg', '100.000'],
    ['Spices Mix', 'kg', '2.000'],
    ['White Salt', 'kg', '20.000'],
    ['Black Salt', 'kg', '20.000'],
    ['Panni 10x14', 'kg', '3.000'],
    ['Panni 7x10', 'kg', '3.000'],
    ['Maida', 'kg', '100.000'],
    ['LDO', 'litre', '30.000'],
    ['Achar', 'packet', '100.000'],
    ['Bora 24x36', 'piece', '100.000'],
    ['Bora 24x42', 'piece', '100.000'],
    ['Panni rangin 10x14', 'kg', '5.000'],
];
```

- [x] **Step 4: Verify the counts**

```bash
cd backend && php -r 'foreach (["customers" => 40, "suppliers" => 6, "materials" => 16] as $f => $n) {
  $rows = require "database/seed_data/shreerajshyamaji/$f.php";
  printf("%-10s %d (expected %d) %s\n", $f, count($rows), $n, count($rows) === $n ? "OK" : "MISMATCH");
}'
```

Expected: all three `OK`.

- [x] **Step 5: Commit**

```bash
cd backend && git add database/seed_data/shreerajshyamaji/
git commit -m "data: add Shree Raj Shyama Ji customers, suppliers and materials"
```

---

### Task 6: Purchases data file

**Files:**
- Create: `backend/database/seed_data/shreerajshyamaji/purchases.php`

- [x] **Step 1: Create the file**

```php
<?php
// database/seed_data/shreerajshyamaji/purchases.php
//
// [date, material, qty, unit_cost, supplier]. Rs 3,42,305 across 23 rows.
// qty is in the material's own unit -- Refined Oil in Tina, not litres.
//
// Two 04-Jun rows named suppliers absent from the master ("Spice World Traders"
// for Maida, "PackTech Industries" for Refined Oil). Both are remapped to the
// supplier who supplied that material on every other date.

return [
    ['2026-04-21', 'Besan (Gram Flour)', '400.000', '55.00', 'Kamakhya GKP'],
    ['2026-04-21', 'Chawal Anta', '400.000', '29.00', 'Kamakhya GKP'],
    ['2026-04-21', 'Refined Oil', '20.000', '2450.00', 'Balaji Trader Hata'],
    ['2026-04-21', 'White Salt', '50.000', '25.00', 'Balaji Trader Hata'],
    ['2026-04-21', 'Black Salt', '50.000', '25.00', 'Balaji Trader Hata'],
    ['2026-04-21', 'Maida', '500.000', '29.00', 'Floar Mill Hata'],
    ['2026-04-21', 'Peanuts', '50.000', '123.00', 'Balaji Trader Hata'],
    ['2026-04-21', 'Panni 10x14', '5.000', '220.00', 'PPP (Panni) Shambu GKP'],
    ['2026-04-21', 'Panni 7x10', '5.000', '95.00', 'PPP (Panni) Shambu GKP'],
    ['2026-04-21', 'LDO', '480.000', '73.00', 'LDO Supplier'],
    ['2026-04-28', 'Panni 10x14', '5.000', '220.00', 'PPP (Panni) Shambu GKP'],
    ['2026-04-28', 'Refined Oil', '20.000', '2500.00', 'Balaji Trader Hata'],
    ['2026-05-22', 'Maida', '1000.000', '29.00', 'Floar Mill Hata'],
    ['2026-05-22', 'Refined Oil', '10.000', '2550.00', 'Balaji Trader Hata'],
    ['2026-06-04', 'Maida', '1000.000', '29.00', 'Floar Mill Hata'],
    ['2026-06-04', 'Refined Oil', '20.000', '2550.00', 'Balaji Trader Hata'],
    ['2026-06-04', 'Peanuts', '50.000', '123.00', 'Balaji Trader Hata'],
    ['2026-06-04', 'Achar', '3.000', '60.00', 'Balaji Trader Hata'],
    ['2026-06-04', 'Panni 7x10', '10.000', '220.00', 'PPP (Panni) Shambu GKP'],
    ['2026-06-04', 'Panni 10x14', '10.000', '220.00', 'PPP (Panni) Shambu GKP'],
    ['2026-06-04', 'Bora 24x36', '200.000', '10.00', 'PPP (Bora) Shambu GKP'],
    ['2026-06-04', 'Bora 24x42', '50.000', '13.00', 'PPP (Bora) Shambu GKP'],
    ['2026-06-04', 'Panni rangin 10x14', '3.000', '320.00', 'PPP (Panni) Shambu GKP'],
];
```

- [x] **Step 2: Verify the total**

```bash
cd backend && php -r '$r = require "database/seed_data/shreerajshyamaji/purchases.php";
$t = "0.00"; foreach ($r as [$d,$m,$q,$c,$s]) { $t = bcadd($t, bcmul($q, $c, 2), 2); }
printf("%d rows, total %s (expected 342305.00)\n", count($r), $t);'
```

Expected: `23 rows, total 342305.00 (expected 342305.00)`

- [x] **Step 3: Commit**

```bash
cd backend && git add database/seed_data/shreerajshyamaji/purchases.php
git commit -m "data: add Shree Raj Shyama Ji purchases"
```

---

### Task 7: Sales and payments data files

**Files:**
- Create: `backend/database/seed_data/shreerajshyamaji/sales.php`
- Create: `backend/database/seed_data/shreerajshyamaji/payments.php`

- [x] **Step 1: Create `sales.php`**

Flat lines, deliberately: this is a direct transcription of the owner's sheet,
and the seeder groups them into sales by `(customer, date)`. Keeping the file
shaped like the source means a re-export can be diffed against it.

```php
<?php
// database/seed_data/shreerajshyamaji/sales.php
//
// [date, customer, village, product, pack, qty, amount]. 103 lines,
// Rs 1,69,123 over 1,653.8 kg. The seeder groups these into 59 sales by
// (customer, date) and derives each line's rate as amount / qty.
//
// Customer is matched on name AND village: two names repeat across villages.
//
// Rates vary per customer for the same pack -- Senvda 800g runs Rs 72 to Rs 80.
// That is real, not noise: sale lines freeze the rate actually charged.
//
// The 11-Jun Byash ji line is a return: negative qty and amount. The schema
// documents sale_lines.qty as "negative qty = a return line (PRD §7 returns)".

return [
    ['2026-05-04', 'Manish ji', 'Hata', 'senvda', '800g', 46, '3404'],
    ['2026-05-18', 'Manish ji', 'Hata', 'senvda', '800g', 50, '3750'],
    ['2026-05-24', 'Manish ji', 'Hata', 'senvda', '800g', 125, '9125'],
    ['2026-06-08', 'Manish ji', 'Hata', 'mix_sev', '800g', 50, '5250'],
    ['2026-05-04', 'Byash ji', 'Bhaisahi', 'senvda', '800g', 15, '1110'],
    ['2026-05-04', 'Byash ji', 'Bhaisahi', 'sev', '800g', 15, '1320'],
    ['2026-06-11', 'Byash ji', 'Bhaisahi', 'senvda', '800g', -9, '-666'],
    ['2026-05-04', 'Vikash ji', 'Asna', 'sev', '1kg', 20, '2240'],
    ['2026-05-04', 'Amit ji', 'Jhingrahiyan', 'sev', '800g', 10, '900'],
    ['2026-05-04', 'Raju', 'Harpur', 'sev', '800g', 26, '2288'],
    ['2026-05-06', 'Raju', 'Harpur', 'senvda', '800g', 24, '1776'],
    ['2026-05-20', 'Raju', 'Harpur', 'senvda', '800g', 47, '3478'],
    ['2026-05-31', 'Raju', 'Harpur', 'senvda', '800g', 50, '3700'],
    ['2026-06-08', 'Raju', 'Harpur', 'senvda', '800g', 25, '1825'],
    ['2026-06-11', 'Raju', 'Harpur', 'senvda', '800g', 25, '1850'],
    ['2026-06-11', 'Raju', 'Harpur', 'mix_sev', '800g', 10, '1060'],
    ['2026-05-23', 'Krishna ji', 'Sohsa', 'mix_sev', '1kg', 8, '944'],
    ['2026-05-18', 'Ache lal', 'Satbhariyan', 'senvda', '800g', 5, '380'],
    ['2026-05-18', 'Ache lal', 'Satbhariyan', 'senvda', '350g', 10, '340'],
    ['2026-05-18', 'Dwivedi ji', 'Aziz', 'senvda', '300g', 20, '600'],
    ['2026-06-11', 'Dwivedi ji', 'Aziz', 'senvda', '300g', 20, '600'],
    ['2026-05-23', 'Girja Sankar', 'Sohsa', 'senvda', '700g', 25, '1750'],
    ['2026-05-23', 'Girja Sankar', 'Sohsa', 'sev', '800g', 25, '2375'],
    ['2026-05-23', 'Girja Sankar', 'Sohsa', 'senvda', '350g', 20, '700'],
    ['2026-05-18', 'Richa Bakers', 'Aziz', 'senvda', '350g', 10, '350'],
    ['2026-05-23', 'Richa Bakers', 'Aziz', 'senvda', '350g', 10, '350'],
    ['2026-06-11', 'Richa Bakers', 'Aziz', 'mix_sev', '800g', 10, '1060'],
    ['2026-06-11', 'Richa Bakers', 'Aziz', 'senvda', '350g', 10, '350'],
    ['2026-05-18', 'Yadav ji', 'Aziz', 'senvda', '350g', 10, '350'],
    ['2026-05-18', 'Yadav ji', 'Aziz', 'sev', '350g', 10, '410'],
    ['2026-05-18', 'Yadav ji', 'Aziz', 'mix_sev', '350g', 10, '430'],
    ['2026-06-02', 'Yadav ji', 'Aziz', 'senvda', '350g', 10, '350'],
    ['2026-05-17', 'Guppta ji', 'Nanhu mudera', 'senvda', '800g', 1, '80'],
    ['2026-05-17', 'Guppta ji', 'Nanhu mudera', 'mix_sev', '1kg', 1, '130'],
    ['2026-05-17', 'Guppta ji', 'Nanhu mudera', 'sev', '1kg', 1, '120'],
    ['2026-05-24', 'Guppta ji', 'Nanhu mudera', 'senvda', '800g', 5, '400'],
    ['2026-05-24', 'Guppta ji', 'Nanhu mudera', 'sev', '800g', 5, '500'],
    ['2026-06-07', 'Guppta ji', 'Nanhu mudera', 'senvda', '1kg', 6, '660'],
    ['2026-06-07', 'Guppta ji', 'Nanhu mudera', 'sev', '1kg', 5, '650'],
    ['2026-05-17', 'Bajarangi', 'Mathauli', 'senvda', '800g', 15, '1110'],
    ['2026-05-17', 'Bajarangi', 'Mathauli', 'senvda', '350g', 20, '660'],
    ['2026-06-11', 'Amit ji', 'Jhingrahiyan', 'mix_sev', '1kg', 15, '1800'],
    ['2026-06-11', 'Amit ji', 'Jhingrahiyan', 'senvda', '1kg', 3, '300'],
    ['2026-06-11', 'Amit ji', 'Jhingrahiyan', 'sev', '1kg', 7, '770'],
    ['2026-06-11', 'Amit ji', 'Jhingrahiyan', 'mix_sev', '800g', 15, '1590'],
    ['2026-05-16', 'Santosh Singh', 'Harpur', 'senvda', '800g', 24, '1800'],
    ['2026-05-11', 'Vishnu', 'Ragarganj', 'sev', '1kg', 25, '2750'],
    ['2026-05-11', 'Vishnu', 'Ragarganj', 'mix_sev', '1kg', 25, '3000'],
    ['2026-05-11', 'Vishnu', 'Ragarganj', 'senvda', '1kg', 19, '1900'],
    ['2026-06-02', 'Vishnu', 'Ragarganj', 'senvda', '1kg', 50, '4500'],
    ['2026-06-11', 'Vishnu', 'Ragarganj', 'mix_sev', '800g', 25, '2625'],
    ['2026-05-08', 'Munna', 'Bankatawan', 'senvda', '375g', 40, '1400'],
    ['2026-05-08', 'Munna', 'Bankatawan', 'senvda', '350g', 31, '1023'],
    ['2026-05-08', 'Munna', 'Bankatawan', 'senvda', '900g', 24, '2040'],
    ['2026-05-08', 'Munna', 'Bankatawan', 'sev', '350g', 40, '1520'],
    ['2026-05-25', 'Munna', 'Bankatawan', 'senvda', '900g', 25, '2150'],
    ['2026-05-25', 'Munna', 'Bankatawan', 'senvda', '350g', 96, '3264'],
    ['2026-06-07', 'Munna', 'Bankatawan', 'sev', '350g', 61, '2318'],
    ['2026-05-05', 'Rajan Ke Chana', 'Pattan', 'sev', '350g', 10, '400'],
    ['2026-05-05', 'Rajan Ke Chana', 'Pattan', 'mix_sev', '250g', 10, '320'],
    ['2026-05-07', 'Santosh Singh', 'Aziz', 'senvda', '800g', 27, '2052'],
    ['2026-05-19', 'Santosh Singh', 'Aziz', 'sev', '900g', 25, '2625'],
    ['2026-06-11', 'Santosh Singh', 'Aziz', 'mix_sev', '900g', 25, '3000'],
    ['2026-06-11', 'Santosh Singh', 'Aziz', 'sev', '900g', 15, '1575'],
    ['2026-06-04', 'Rajan', 'Pattan', 'sev', '800g', 15, '1320'],
    ['2026-06-04', 'Rajan', 'Pattan', 'senvda', '800g', 15, '1110'],
    ['2026-05-25', 'Rajan', 'Pattan', 'sev', '800g', 15, '1425'],
    ['2026-05-25', 'Rajan', 'Pattan', 'mix_sev', '800g', 15, '1455'],
    ['2026-06-10', 'Ghore lal', 'Mathauli', 'sev', '900g', 25, '2650'],
    ['2026-06-10', 'Ghore lal', 'Mathauli', 'sev', '350g', 20, '840'],
    ['2026-06-10', 'Ghore lal', 'Mathauli', 'senvda', '900g', 25, '2125'],
    ['2026-06-10', 'Ghore lal', 'Mathauli', 'senvda', '350g', 20, '660'],
    ['2026-06-10', 'Ghore lal', 'Mathauli', 'mix_sev', '800g', 10, '1050'],
    ['2026-06-10', 'Ghore lal', 'Mathauli', 'mix_sev', '300g', 10, '390'],
    ['2026-05-18', 'Ghore lal', 'Mathauli', 'sev', '900g', 25, '2650'],
    ['2026-05-05', 'Ghore lal', 'Mathauli', 'sev', '1kg', 8, '990'],
    ['2026-05-05', 'Ghore lal', 'Mathauli', 'sev', '800g', 10, '880'],
    ['2026-05-05', 'Ghore lal', 'Mathauli', 'sev', '400g', 10, '440'],
    ['2026-05-05', 'Ghore lal', 'Mathauli', 'sev', '350g', 10, '390'],
    ['2026-05-05', 'Ghore lal', 'Mathauli', 'senvda', '900g', 9, '729'],
    ['2026-05-05', 'Ghore lal', 'Mathauli', 'senvda', '400g', 10, '360'],
    ['2026-05-05', 'Ghore lal', 'Mathauli', 'mix_sev', '400g', 10, '480'],
    ['2026-05-07', 'Ghore lal', 'Mathauli', 'senvda', '800g', 48, '3456'],
    ['2026-05-07', 'Ghore lal', 'Mathauli', 'senvda', '350g', 70, '2310'],
    ['2026-05-11', 'Ghore lal', 'Mathauli', 'senvda', '800g', 48, '3600'],
    ['2026-05-11', 'Ghore lal', 'Mathauli', 'senvda', '1kg', 17, '1500'],
    ['2026-05-11', 'Ghore lal', 'Mathauli', 'senvda', '350g', 40, '1280'],
    ['2026-05-28', 'Golu ji', 'Hata', 'senvda', '800g', 48, '3600'],
    ['2026-05-31', 'Anarudh', 'Bhiswan', 'senvda', '800g', 25, '1800'],
    ['2026-05-31', 'Sharma ji', 'Gaderi pati', 'sev', '900g', 50, '5300'],
    ['2026-06-07', 'Sharma ji', 'Gaderi pati', 'senvda', '350g', 50, '1600'],
    ['2026-06-01', 'Ajay Singh', 'Ahirauli', 'mix_sev', '900g', 12, '1296'],
    ['2026-06-01', 'Ajay Singh', 'Ahirauli', 'senvda', '700g', 14, '980'],
    ['2026-06-02', 'Vikash ji', 'Lohepar', 'senvda', '350g', 60, '1920'],
    ['2026-06-07', 'Bhim ji', 'Mathauli', 'senvda', '1kg', 50, '3800'],
    ['2026-06-07', 'Bhim ji', 'Mathauli', 'senvda', '1kg', 40, '3800'],
    ['2026-06-10', 'Madhav', 'Ragarganj', 'senvda', '350g', 52, '1716'],
    ['2026-06-10', 'Santosh Jaysawal', 'parsauni', 'senvda', '800g', 25, '1925'],
    ['2026-06-07', 'Parthiv', 'Khairatwa', 'sev', '950g', 25, '2625'],
    ['2026-06-15', 'Parthiv', 'Khairatwa', 'sev', '950g', 25, '2625'],
    ['2026-06-02', 'Munna Singh', 'Nandu Mundera', 'sev', '1kg', 5, '615'],
    ['2026-06-02', 'Munna Singh', 'Nandu Mundera', 'sev', '350g', 10, '440'],
    ['2026-06-02', 'Munna Singh', 'Nandu Mundera', 'senvda', '1kg', 2, '210'],
];
```

**Note on the two 07-Jun Bhim ji lines:** both are correct. The owner confirmed
they are not a duplicate — 50 packs at Rs 76 and 40 at Rs 95, same date, same
pack. Do not deduplicate them.

- [x] **Step 2: Create `payments.php`**

```php
<?php
// database/seed_data/shreerajshyamaji/payments.php
//
// [date, customer, village, amount]. 46 rows, Rs 1,26,229 -- leaving
// Rs 42,894 outstanding across 22 customers. Mode is 'cash' throughout: the
// owner's ledger records amounts and dates but not the tender.

return [
    ['2026-05-18', 'Manish ji', 'Hata', '1000'],
    ['2026-05-19', 'Manish ji', 'Hata', '2404'],
    ['2026-06-24', 'Manish ji', 'Hata', '3750'],
    ['2026-06-08', 'Manish ji', 'Hata', '9120'],
    ['2026-06-14', 'Manish ji', 'Hata', '5250'],
    ['2026-06-11', 'Byash ji', 'Bhaisahi', '779'],
    ['2026-05-04', 'Vikash ji', 'Asna', '500'],
    ['2026-05-04', 'Vikash ji', 'Asna', '1000'],
    ['2026-05-04', 'Vikash ji', 'Asna', '740'],
    ['2026-05-04', 'Amit ji', 'Jhingrahiyan', '500'],
    ['2026-05-04', 'Amit ji', 'Jhingrahiyan', '400'],
    ['2026-06-11', 'Amit ji', 'Jhingrahiyan', '2170'],
    ['2026-05-04', 'Raju', 'Harpur', '2288'],
    ['2026-05-06', 'Raju', 'Harpur', '1776'],
    ['2026-05-20', 'Raju', 'Harpur', '3450'],
    ['2026-05-31', 'Raju', 'Harpur', '3700'],
    ['2026-06-08', 'Raju', 'Harpur', '1825'],
    ['2026-06-11', 'Raju', 'Harpur', '2865'],
    ['2026-05-23', 'Krishna ji', 'Sohsa', '500'],
    ['2026-05-18', 'Ache lal', 'Satbhariyan', '720'],
    ['2026-05-18', 'Dwivedi ji', 'Aziz', '600'],
    ['2026-05-23', 'Girja Sankar', 'Sohsa', '4800'],
    ['2026-05-23', 'Girja Sankar', 'Sohsa', '25'],
    ['2026-06-02', 'Richa Bakers', 'Aziz', '700'],
    ['2026-06-08', 'Yadav ji', 'Aziz', '1540'],
    ['2026-06-07', 'Guppta ji', 'Nanhu mudera', '1230'],
    ['2026-06-11', 'Bajarangi', 'Mathauli', '1755'],
    ['2026-05-16', 'Santosh Singh', 'Harpur', '1000'],
    ['2026-06-11', 'Vishnu', 'Ragarganj', '11400'],
    ['2026-06-07', 'Munna', 'Bankatawan', '13715'],
    ['2026-05-05', 'Rajan Ke Chana', 'Pattan', '420'],
    ['2026-06-06', 'Santosh Singh', 'Aziz', '4677'],
    ['2026-06-04', 'Rajan', 'Pattan', '2410'],
    ['2026-05-18', 'Ghore lal', 'Mathauli', '1000'],
    ['2026-05-05', 'Ghore lal', 'Mathauli', '4269'],
    ['2026-05-07', 'Ghore lal', 'Mathauli', '5766'],
    ['2026-05-11', 'Ghore lal', 'Mathauli', '6380'],
    ['2026-06-10', 'Golu ji', 'Hata', '1500'],
    ['2026-05-31', 'Anarudh', 'Bhiswan', '1800'],
    ['2026-05-31', 'Sharma ji', 'Gaderi pati', '5300'],
    ['2026-06-01', 'Ajay Singh', 'Ahirauli', '800'],
    ['2026-06-02', 'Vikash ji', 'Lohepar', '1890'],
    ['2026-06-07', 'Bhim ji', 'Mathauli', '1000'],
    ['2026-06-10', 'Santosh Jaysawal', 'parsauni', '1000'],
    ['2026-06-15', 'Parthiv', 'Khairatwa', '5250'],
    ['2026-06-02', 'Munna Singh', 'Nandu Mundera', '1265'],
];
```

- [x] **Step 3: Verify the totals**

```bash
cd backend && php -r '
$s = require "database/seed_data/shreerajshyamaji/sales.php";
$p = require "database/seed_data/shreerajshyamaji/payments.php";
$st = "0.00"; foreach ($s as $r) { $st = bcadd($st, $r[6], 2); }
$pt = "0.00"; foreach ($p as $r) { $pt = bcadd($pt, $r[3], 2); }
printf("sales %d rows = %s (expected 169123.00)\n", count($s), $st);
printf("payments %d rows = %s (expected 126229.00)\n", count($p), $pt);
printf("outstanding = %s (expected 42894.00)\n", bcsub($st, $pt, 2));'
```

Expected:
```
sales 103 rows = 169123.00 (expected 169123.00)
payments 46 rows = 126229.00 (expected 126229.00)
outstanding = 42894.00 (expected 42894.00)
```

- [x] **Step 4: Commit**

```bash
cd backend && git add database/seed_data/shreerajshyamaji/sales.php database/seed_data/shreerajshyamaji/payments.php
git commit -m "data: add Shree Raj Shyama Ji sales lines and payments"
```

---

### Task 8: Production data file

**Files:**
- Create: `backend/database/seed_data/shreerajshyamaji/production.php`

- [x] **Step 1: Create the file**

```php
<?php
// database/seed_data/shreerajshyamaji/production.php
//
// [date, product, output_kg, [[material, qty], ...]].
//
// RECONSTRUCTED, not transcribed -- see the spec's "The production problem".
// The owner's log covers 3 batches / 770 kg against 1,654 kg sold, with no
// Senvda batch at all, and Rs 2.34 lakh of consumption against it. Seeding that
// verbatim gives Rs 304/kg cost against Rs 102/kg revenue.
//
// The owner's three batches (15/16/17-May) are kept. Four are added to cover
// what was actually sold. Consumption follows a per-kilo recipe:
//   0.85 kg flour + 0.20 kg oil + Rs 4.00 of packing and salt.
// Flour blend: Senvda 100% maida; Sev 50/50 besan/chawal anta;
// Mix Sev 60/15/25 besan/peanuts/chawal anta.
//
// Besan is the binding constraint at 400 kg purchased -- it is what sets those
// blends. Refined Oil qty is in TINA, the unit it is stocked in.
//
// Packing and salt are allocated pro-rata by batch output, at 58.2% of each
// material purchased, which is the Rs 4.00/kg allowance.

return [
    ['2026-04-25', 'senvda', '400.000', [
        ['Maida', '340.000'],
        ['Refined Oil', '5.333'],
        ['White Salt', '5.985'],
        ['Black Salt', '5.985'],
        ['Panni 10x14', '2.394'],
        ['Panni 7x10', '1.795'],
        ['Bora 24x36', '23.938'],
        ['Bora 24x42', '5.985'],
        ['Panni rangin 10x14', '0.359'],
        ['Achar', '0.359'],
    ]],
    ['2026-05-15', 'sev', '345.000', [
        ['Besan (Gram Flour)', '146.625'],
        ['Chawal Anta', '146.625'],
        ['Refined Oil', '4.600'],
        ['White Salt', '5.162'],
        ['Black Salt', '5.162'],
        ['Panni 10x14', '2.065'],
        ['Panni 7x10', '1.549'],
        ['Bora 24x36', '20.647'],
        ['Bora 24x42', '5.162'],
        ['Panni rangin 10x14', '0.310'],
        ['Achar', '0.310'],
    ]],
    ['2026-05-16', 'sev', '80.000', [
        ['Besan (Gram Flour)', '34.000'],
        ['Chawal Anta', '34.000'],
        ['Refined Oil', '1.067'],
        ['White Salt', '1.197'],
        ['Black Salt', '1.197'],
        ['Panni 10x14', '0.479'],
        ['Panni 7x10', '0.359'],
        ['Bora 24x36', '4.788'],
        ['Bora 24x42', '1.197'],
        ['Panni rangin 10x14', '0.072'],
        ['Achar', '0.072'],
    ]],
    ['2026-05-17', 'mix_sev', '345.000', [
        ['Besan (Gram Flour)', '175.950'],
        ['Peanuts', '43.987'],
        ['Chawal Anta', '73.312'],
        ['Refined Oil', '4.600'],
        ['White Salt', '5.162'],
        ['Black Salt', '5.162'],
        ['Panni 10x14', '2.065'],
        ['Panni 7x10', '1.549'],
        ['Bora 24x36', '20.647'],
        ['Bora 24x42', '5.162'],
        ['Panni rangin 10x14', '0.310'],
        ['Achar', '0.310'],
    ]],
    ['2026-05-20', 'senvda', '400.000', [
        ['Maida', '340.000'],
        ['Refined Oil', '5.333'],
        ['White Salt', '5.985'],
        ['Black Salt', '5.985'],
        ['Panni 10x14', '2.394'],
        ['Panni 7x10', '1.795'],
        ['Bora 24x36', '23.938'],
        ['Bora 24x42', '5.985'],
        ['Panni rangin 10x14', '0.359'],
        ['Achar', '0.359'],
    ]],
    ['2026-06-05', 'senvda', '355.000', [
        ['Maida', '301.750'],
        ['Refined Oil', '4.733'],
        ['White Salt', '5.311'],
        ['Black Salt', '5.311'],
        ['Panni 10x14', '2.125'],
        ['Panni 7x10', '1.593'],
        ['Bora 24x36', '21.245'],
        ['Bora 24x42', '5.311'],
        ['Panni rangin 10x14', '0.319'],
        ['Achar', '0.319'],
    ]],
    ['2026-06-05', 'sev', '20.000', [
        ['Besan (Gram Flour)', '8.500'],
        ['Chawal Anta', '8.500'],
        ['Refined Oil', '0.267'],
        ['White Salt', '0.299'],
        ['Black Salt', '0.299'],
        ['Panni 10x14', '0.120'],
        ['Panni 7x10', '0.090'],
        ['Bora 24x36', '1.197'],
        ['Bora 24x42', '0.299'],
        ['Panni rangin 10x14', '0.018'],
        ['Achar', '0.018'],
    ]],
];
```

- [x] **Step 2: Verify output and that no material overruns its purchases**

```bash
cd backend && php -r '
$b = require "database/seed_data/shreerajshyamaji/production.php";
$p = require "database/seed_data/shreerajshyamaji/purchases.php";
$bought = []; foreach ($p as [$d,$m,$q,$c,$s]) { $bought[$m] = bcadd($bought[$m] ?? "0.000", $q, 3); }
$used = []; $out = "0.000";
foreach ($b as [$d,$prod,$kg,$cons]) {
  $out = bcadd($out, $kg, 3);
  foreach ($cons as [$m,$q]) { $used[$m] = bcadd($used[$m] ?? "0.000", $q, 3); }
}
printf("%d batches, output %s kg (expected 1945.000)\n", count($b), $out);
$bad = 0;
foreach ($used as $m => $q) {
  $have = $bought[$m] ?? "0.000";
  $close = bcsub($have, $q, 3);
  if (bccomp($close, "0.000", 3) < 0) { $bad++; printf("  NEGATIVE %-22s used %s of %s\n", $m, $q, $have); }
}
printf("materials overrun: %d (expected 0)\n", $bad);'
```

Expected:
```
7 batches, output 1945.000 kg (expected 1945.000)
materials overrun: 0 (expected 0)
```

- [x] **Step 3: Commit**

```bash
cd backend && git add database/seed_data/shreerajshyamaji/production.php
git commit -m "data: add reconstructed Shree Raj Shyama Ji production batches"
```

---

### Task 9: Seeder — masters

**Files:**
- Create: `backend/database/seeders/ShreeRajShyamajiSeeder.php`
- Test: `backend/tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php`

- [x] **Step 1: Write the failing test**

Create `backend/tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php`:

```php
<?php
// tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\RawMaterial;
use App\Models\Supplier;
use Database\Seeders\ShreeRajShyamajiSeeder;

/** The seeder writes on pgsql_migrate, so assertions read from there too. */
function srsBusiness(): Business
{
    return Business::on('pgsql_migrate')->where('name', 'Shree Raj Shyama Ji Namkeen')->firstOrFail();
}

function srsCount(string $class): int
{
    return $class::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count();
}

beforeEach(function () {
    $this->seed(ShreeRajShyamajiSeeder::class);
});

it('seeds the masters onto one business', function () {
    expect(srsCount(Customer::class))->toBe(40)
        ->and(srsCount(Supplier::class))->toBe(6)
        ->and(srsCount(RawMaterial::class))->toBe(16)
        ->and(srsCount(Product::class))->toBe(3)
        ->and(srsCount(PackSize::class))->toBe(17)
        ->and(srsCount(ProductPack::class))->toBe(21);
});

it('keeps same-named customers in different villages apart', function () {
    $rows = Customer::on('pgsql_migrate')
        ->where('business_id', srsBusiness()->id)
        ->where('name', 'Santosh Singh')
        ->orderBy('village')
        ->pluck('village')
        ->all();

    expect($rows)->toBe(['Aziz', 'Harpur']);
});

it('records oil in the unit it is bought in', function () {
    $oil = RawMaterial::on('pgsql_migrate')
        ->where('business_id', srsBusiness()->id)
        ->where('name', 'Refined Oil')
        ->firstOrFail();

    expect($oil->unit)->toBe('tina');
});
```

- [x] **Step 2: Run test to verify it fails**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php
```

Expected: FAIL — `Class "Database\Seeders\ShreeRajShyamajiSeeder" not found`.

- [x] **Step 3: Write the seeder's master half**

Create `backend/database/seeders/ShreeRajShyamajiSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CatalogService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The real records of Shree Raj Shyama Ji Namkeen, Hata — April to June 2026.
 *
 * Replaces the invented demo tenants. Business data lives in
 * database/seed_data/shreerajshyamaji/*.php, one file per master; this class
 * holds the insert logic and nothing else, so a re-export from the owner's
 * spreadsheet replaces a data file rather than editing code.
 *
 * Every write goes through the privileged `pgsql_migrate` connection. A seeder
 * runs outside a request, so no SetTenantContext transaction has set
 * `app.current_tenant`, and the RLS WITH CHECK predicate would reject every
 * tenant-owned insert on the app connection. The app-layer BelongsToTenant
 * scope is a no-op here because `app('tenant.id')` resolves to null, so the
 * explicit business_id values below pass through untouched.
 *
 * Idempotent: masters use updateOrCreate on natural keys, and a business whose
 * customers already exist is treated as fully seeded, so re-running never
 * duplicates the transactional rows.
 */
class ShreeRajShyamajiSeeder extends Seeder
{
    private const CONNECTION = 'pgsql_migrate';

    private const BUSINESS = 'Shree Raj Shyama Ji Namkeen';

    private string $businessId;

    private int $ownerId;

    /** @var array<string, string> "name|village" => customer id */
    private array $customers = [];

    /** @var array<string, string> supplier name => id */
    private array $suppliers = [];

    /** @var array<string, string> material name => id */
    private array $materials = [];

    /** @var array<string, string> template key => product id */
    private array $products = [];

    /** @var array<string, string> "productKey|packKey" => product_pack id */
    private array $packs = [];

    public function run(): void
    {
        $business = Business::on(self::CONNECTION)->updateOrCreate(
            ['name' => self::BUSINESS],
            ['city' => 'Hata', 'default_language' => 'hi', 'plan' => 'trial'],
        );

        $this->businessId = $business->id;
        $this->ownerId = $this->owner($business)->id;

        $this->catalog();
        $this->masters();

        $this->command->info(self::BUSINESS.": seeded catalog and masters.");
    }

    private function owner(Business $business): User
    {
        $user = User::on(self::CONNECTION)->updateOrCreate(
            ['email' => 'owner@vyaparbook.test'],
            ['name' => 'Shree Raj Shyama Ji', 'phone' => '9876500001', 'password' => Hash::make('password123')],
        );

        Membership::on(self::CONNECTION)->updateOrCreate(
            ['user_id' => $user->id, 'business_id' => $business->id],
            ['role' => 'owner'],
        );

        return $user;
    }

    /** @return array<string, mixed> */
    private function data(string $file): array
    {
        return require database_path("seed_data/shreerajshyamaji/{$file}.php");
    }

    private function catalog(): void
    {
        $template = $this->data('catalog');
        $catalog = app(CatalogService::class);

        foreach ($template['products'] as $key => $attrs) {
            $this->products[$key] = Product::on(self::CONNECTION)->updateOrCreate(
                ['business_id' => $this->businessId, 'name_en' => $attrs['name_en']],
                $attrs,
            )->id;
        }

        $sizes = [];
        foreach ($template['pack_sizes'] as $key => $attrs) {
            $sizes[$key] = PackSize::on(self::CONNECTION)->updateOrCreate(
                ['business_id' => $this->businessId, 'label' => $attrs['label']],
                $attrs,
            );
        }

        foreach ($template['product_packs'] as $row) {
            $product = Product::on(self::CONNECTION)->find($this->products[$row['product']]);
            $size = $sizes[$row['pack']];

            $pack = ProductPack::on(self::CONNECTION)->updateOrCreate(
                [
                    'business_id' => $this->businessId,
                    'product_id' => $product->id,
                    'pack_size_id' => $size->id,
                ],
                [
                    'default_sell_price' => $row['default_sell_price'],
                    'default_cost_price' => $catalog->suggestedCostPrice($product, $size),
                ],
            );

            $this->packs[$row['product'].'|'.$row['pack']] = $pack->id;
        }
    }

    private function masters(): void
    {
        foreach ($this->data('customers') as [$name, $village]) {
            $row = Customer::on(self::CONNECTION)->updateOrCreate(
                ['business_id' => $this->businessId, 'name' => $name, 'village' => $village],
                ['uuid' => (string) Str::uuid(), 'opening_balance' => '0.00'],
            );

            // Keyed on name AND village: two names repeat across villages.
            $this->customers[$name.'|'.$village] = $row->id;
        }

        foreach ($this->data('suppliers') as $name) {
            $this->suppliers[$name] = Supplier::on(self::CONNECTION)->updateOrCreate(
                ['business_id' => $this->businessId, 'name' => $name],
                ['uuid' => (string) Str::uuid(), 'opening_balance' => '0.00'],
            )->id;
        }

        foreach ($this->data('materials') as [$name, $unit, $reorder]) {
            $this->materials[$name] = RawMaterial::on(self::CONNECTION)->updateOrCreate(
                ['business_id' => $this->businessId, 'name' => $name],
                ['uuid' => (string) Str::uuid(), 'unit' => $unit, 'reorder_level' => $reorder],
            )->id;
        }
    }
}
```

`Product::$fillable` is `['business_id', 'name_hi', 'name_en', 'base_cost_per_kg']`,
so the catalog attributes mass-assign cleanly and `name_en` is a usable natural
key.

- [x] **Step 4: Run test to verify it passes**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php
```

Expected: PASS, 3 tests.

- [x] **Step 5: Commit**

```bash
cd backend && git add database/seeders/ShreeRajShyamajiSeeder.php tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php
git commit -m "feat: seed Shree Raj Shyama Ji catalog, customers, suppliers and materials"
```

---

### Task 10: Seeder — purchases and their stock movements

**Files:**
- Modify: `backend/database/seeders/ShreeRajShyamajiSeeder.php`
- Test: `backend/tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php`

- [x] **Step 1: Write the failing test**

Append to the test file (and add
`use App\Models\Purchase; use App\Models\StockMovement; use App\Services\StockService;`
to the imports):

```php
it('seeds every purchase with the total the invoices add up to', function () {
    $rows = Purchase::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();

    expect($rows)->toHaveCount(23);

    $total = $rows->reduce(fn (string $c, $p) => bcadd($c, (string) $p->total, 2), '0.00');
    expect($total)->toBe('342305.00');
});

it('raises stock for every purchase, so on-hand is not zero', function () {
    // on-hand is a sum over stock_movements, not a column: a Purchase row alone
    // moves nothing. PurchaseWriter pairs each with a positive `in` movement.
    $ins = StockMovement::on('pgsql_migrate')
        ->where('business_id', srsBusiness()->id)
        ->whereNotNull('purchase_id')
        ->get();

    expect($ins)->toHaveCount(23);
    expect($ins->every(fn ($m) => bccomp((string) $m->qty, '0', 3) > 0))->toBeTrue();
});
```

- [x] **Step 2: Run test to verify it fails**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php --filter="purchase"
```

Expected: FAIL — 0 purchases found, expected 23.

- [x] **Step 3: Add the purchases method**

Add these imports to the seeder:

```php
use App\Models\Purchase;
use App\Models\StockMovement;
```

Call it from `run()`, after `$this->masters();`:

```php
        $this->purchases();
```

And add the method:

```php
    /**
     * Purchases, each with the positive `in` movement that actually raises
     * stock — on-hand is Σ stock_movements.qty, so a Purchase row on its own
     * would leave every material reading zero. Mirrors PurchaseWriter.
     */
    private function purchases(): void
    {
        foreach ($this->data('purchases') as [$date, $material, $qty, $unitCost, $supplier]) {
            $purchase = Purchase::on(self::CONNECTION)->create([
                'business_id' => $this->businessId,
                'uuid' => (string) Str::uuid(),
                'supplier_id' => $this->suppliers[$supplier],
                'raw_material_id' => $this->materials[$material],
                'purchase_date' => $date,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'total' => bcmul($qty, $unitCost, 2),
            ]);
            $purchase->created_by = $this->ownerId;
            $purchase->save();

            $movement = new StockMovement([
                'business_id' => $this->businessId,
                'uuid' => (string) Str::uuid(),
                'raw_material_id' => $this->materials[$material],
                'movement_date' => $date,
                'kind' => 'in',
                'qty' => $qty,
                'purchase_id' => $purchase->id,
            ]);
            $movement->setConnection(self::CONNECTION);
            $movement->created_by = $this->ownerId;
            $movement->save();
        }
    }
```

Guard the transactional half so re-running stays idempotent. At the top of
`purchases()`, add:

```php
        if (Purchase::on(self::CONNECTION)->where('business_id', $this->businessId)->exists()) {
            return;
        }
```

- [x] **Step 4: Run test to verify it passes**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php
```

Expected: PASS, 5 tests.

- [x] **Step 5: Commit**

```bash
cd backend && git add database/seeders/ShreeRajShyamajiSeeder.php tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php
git commit -m "feat: seed purchases with the stock movements that raise on-hand"
```

---

### Task 11: Seeder — sales and payments

**Files:**
- Modify: `backend/database/seeders/ShreeRajShyamajiSeeder.php`
- Test: `backend/tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php`

- [x] **Step 1: Write the failing test**

Append (adding
`use App\Models\Payment; use App\Models\Sale; use App\Models\SaleLine; use App\Services\KhataService;`):

```php
it('seeds the sale lines and groups them into sales by customer and date', function () {
    $lines = SaleLine::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();
    $sales = Sale::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();

    expect($lines)->toHaveCount(103);
    expect($sales)->toHaveCount(59);

    $total = $sales->reduce(fn (string $c, $s) => bcadd($c, (string) $s->total, 2), '0.00');
    expect($total)->toBe('169123.00');
});

it('holds the writer invariant that a sale equals the sum of its lines', function () {
    $sales = Sale::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();

    foreach ($sales as $sale) {
        $sum = SaleLine::on('pgsql_migrate')->where('sale_id', $sale->id)->get()
            ->reduce(fn (string $c, $l) => bcadd($c, (string) $l->line_total, 2), '0.00');

        expect($sum)->toBe(number_format((float) $sale->total, 2, '.', ''), "sale {$sale->id}");
    }
});

it('keeps the return as a negative line rather than deleting the sale', function () {
    // Byash ji returned 9 of 15 packs on 11-Jun. Reversals stay as rows so
    // outstanding remains recomputable (PRD §9).
    $line = SaleLine::on('pgsql_migrate')
        ->where('business_id', srsBusiness()->id)
        ->where('qty', '<', 0)
        ->firstOrFail();

    expect($line->qty)->toBe(-9)
        ->and((string) $line->line_total)->toBe('-666.00');
});

it('leaves the outstanding the owner is actually owed', function () {
    $payments = Payment::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();

    expect($payments)->toHaveCount(46);

    $paid = $payments->reduce(fn (string $c, $p) => bcadd($c, (string) $p->amount, 2), '0.00');
    expect($paid)->toBe('126229.00');
});

it('leaves each customer owing what the owner ledger says', function () {
    // Hand-checked against the bill ledger. These are the numbers a shopkeeper
    // would recognise, so they are the ones worth pinning.
    $khata = app(KhataService::class);
    $expected = [
        'Ghore lal|Mathauli' => '9365.00',    // the biggest debtor
        'Bhim ji|Mathauli' => '6600.00',
        'Byash ji|Bhaisahi' => '985.00',      // after the 9-pack return
        'Manish ji|Hata' => '5.00',           // a Rs 9,125 bill paid Rs 9,120
        'Anarudh|Bhiswan' => '0.00',          // settled in full
        'Mishra ji|Tinahawan' => '0.00',      // in the master, never bought
    ];

    foreach ($expected as $key => $due) {
        [$name, $village] = explode('|', $key);
        $customer = Customer::on('pgsql_migrate')
            ->where('business_id', srsBusiness()->id)
            ->where('name', $name)->where('village', $village)
            ->firstOrFail();
        $customer->setConnection('pgsql_migrate');

        expect($khata->outstandingFor($customer))->toBe($due, $key);
    }
});

it('totals the outstanding across the whole book', function () {
    $business = srsBusiness();

    $sales = Sale::on('pgsql_migrate')->where('business_id', $business->id)->get()
        ->reduce(fn (string $c, $s) => bcadd($c, (string) $s->total, 2), '0.00');
    $paid = Payment::on('pgsql_migrate')->where('business_id', $business->id)->get()
        ->reduce(fn (string $c, $p) => bcadd($c, (string) $p->amount, 2), '0.00');

    expect(bcsub($sales, $paid, 2))->toBe('42894.00');
});

it('charges each customer the rate they were actually given', function () {
    // Senvda 800g runs Rs 72 to Rs 80 depending on the customer. A seeder that
    // used the pack default everywhere would erase that.
    $rates = SaleLine::on('pgsql_migrate')
        ->join('product_packs as pp', 'pp.id', '=', 'sale_lines.product_pack_id')
        ->join('pack_sizes as ps', 'ps.id', '=', 'pp.pack_size_id')
        ->join('products as p', 'p.id', '=', 'pp.product_id')
        ->where('sale_lines.business_id', srsBusiness()->id)
        ->where('p.name_en', 'Senvda')->where('ps.label', '800g')
        ->distinct()->pluck('sale_lines.rate')->map(fn ($r) => (float) $r)->all();

    expect(min($rates))->toBe(72.0)->and(max($rates))->toBe(80.0);
});
```

- [x] **Step 2: Run test to verify it fails**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php --filter="sale lines"
```

Expected: FAIL — 0 lines found, expected 103.

- [x] **Step 3: Add the sales and payments methods**

Add imports:

```php
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleLine;
```

Call from `run()` after `$this->purchases();`:

```php
        $this->sales();
        $this->payments();
```

Add the methods:

```php
    /**
     * Sale lines grouped into sales by (customer, date), as the owner's book
     * records them: one visit, several products.
     *
     * The rate is derived per line from what was charged, never taken from the
     * pack default — the same pack sells at different prices to different
     * customers, and flattening that would misstate every margin.
     */
    private function sales(): void
    {
        if (Sale::on(self::CONNECTION)->where('business_id', $this->businessId)->exists()) {
            return;
        }

        $grouped = [];
        foreach ($this->data('sales') as $row) {
            [$date, $name, $village, $product, $pack, $qty, $amount] = $row;
            $grouped[$date.'|'.$name.'|'.$village][] = [$product, $pack, $qty, $amount];
        }

        foreach ($grouped as $key => $lines) {
            [$date, $name, $village] = explode('|', $key);

            $sale = new Sale([
                'business_id' => $this->businessId,
                'uuid' => (string) Str::uuid(),
                'customer_id' => $this->customers[$name.'|'.$village],
                'sale_date' => $date,
            ]);
            $sale->setConnection(self::CONNECTION);
            $sale->created_by = $this->ownerId;
            $sale->total = '0.00';
            $sale->save();

            $total = '0.00';
            foreach ($lines as [$product, $pack, $qty, $amount]) {
                $lineTotal = bcadd($amount, '0', 2);
                // Rate is per pack and always positive; the sign lives on qty,
                // so a return reads as "9 packs back at the price paid".
                $rate = bcdiv($lineTotal, (string) $qty, 2);
                $total = bcadd($total, $lineTotal, 2);

                $line = new SaleLine([
                    'business_id' => $this->businessId,
                    'sale_id' => $sale->id,
                    'product_pack_id' => $this->packs[$product.'|'.$pack],
                    'qty' => $qty,
                    'rate' => $rate,
                ]);
                $line->setConnection(self::CONNECTION);
                $line->line_total = $lineTotal;
                $line->save();
            }

            $sale->total = $total;
            $sale->save();
        }
    }

    /** Mode is 'cash' throughout: the owner's ledger records amount and date, not tender. */
    private function payments(): void
    {
        if (Payment::on(self::CONNECTION)->where('business_id', $this->businessId)->exists()) {
            return;
        }

        foreach ($this->data('payments') as [$date, $name, $village, $amount]) {
            $payment = new Payment([
                'business_id' => $this->businessId,
                'uuid' => (string) Str::uuid(),
                'customer_id' => $this->customers[$name.'|'.$village],
                'payment_date' => $date,
                'amount' => bcadd($amount, '0', 2),
                'mode' => 'cash',
            ]);
            $payment->setConnection(self::CONNECTION);
            $payment->created_by = $this->ownerId;
            $payment->save();
        }
    }
```

- [x] **Step 4: Run test to verify it passes**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php
```

Expected: PASS, 10 tests.

- [x] **Step 5: Commit**

```bash
cd backend && git add database/seeders/ShreeRajShyamajiSeeder.php tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php
git commit -m "feat: seed sales lines, their grouping into sales, and payments"
```

---

### Task 12: Seeder — production, consumptions, and the non-negative constraint

**Files:**
- Modify: `backend/database/seeders/ShreeRajShyamajiSeeder.php`
- Test: `backend/tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php`

- [x] **Step 1: Write the failing test**

Append (adding
`use App\Models\MaterialConsumption; use App\Models\ProductionBatch; use App\Services\CogsService;`):

```php
it('seeds the reconstructed batches', function () {
    $batches = ProductionBatch::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();

    expect($batches)->toHaveCount(7);

    $output = $batches->reduce(fn (string $c, $b) => bcadd($c, (string) $b->output_kg, 3), '0.000');
    expect($output)->toBe('1945.000');
});

it('never lets a material close below zero', function () {
    // The check that catches a bad recipe. Consuming stock that was never
    // bought would make the whole valuation fiction.
    $stock = app(StockService::class);

    foreach (RawMaterial::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get() as $material) {
        $onHand = $stock->onHandFor($material);

        expect(bccomp($onHand, '0.000', 3))->toBeGreaterThanOrEqual(0, "{$material->name} closed at {$onHand}");
    }
});

it('costs every product below what it sells for', function () {
    // The point of reconstructing production: the owner's own consumption
    // figures give Rs 304/kg against Rs 102/kg of revenue.
    $revenuePerKg = ['Senvda' => 92.77, 'Sev' => 114.42, 'Mix Sev' => 127.30];
    $costs = app(CogsService::class)->packCosts(srsBusiness()->id);

    expect($costs)->not->toBeEmpty();

    foreach (ProductPack::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get() as $pack) {
        $cost = $costs[$pack->id] ?? null;
        if ($cost === null) {
            continue;
        }

        $product = Product::on('pgsql_migrate')->find($pack->product_id);
        $size = PackSize::on('pgsql_migrate')->find($pack->pack_size_id);
        $costPerKg = (float) $cost->costRupees / (float) $size->weight_kg;
        $margin = ($revenuePerKg[$product->name_en] - $costPerKg) / $revenuePerKg[$product->name_en];

        expect($margin)->toBeGreaterThan(0.20)->toBeLessThan(0.40);
    }
});
```

`CogsService::packCosts()` returns `App\Reports\PackCost`, a readonly DTO with
`public string $costRupees` and `public bool $fromProduction`.

- [x] **Step 2: Run test to verify it fails**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php --filter="reconstructed batches"
```

Expected: FAIL — 0 batches found, expected 7.

- [x] **Step 3: Add the production method**

Add imports:

```php
use App\Models\MaterialConsumption;
use App\Models\ProductionBatch;
```

Call from `run()` after `$this->payments();`:

```php
        $this->production();
```

Add the method:

```php
    /**
     * Batches and what they consumed, each consumption paired with the negative
     * `out` movement that actually lowers stock — mirrors ProductionWriter.
     *
     * RECONSTRUCTED, not transcribed. See the spec: the owner's log covers
     * 770 kg against 1,654 kg sold, with no Senvda batch, so costing it
     * verbatim reports a loss on every sale.
     */
    private function production(): void
    {
        if (ProductionBatch::on(self::CONNECTION)->where('business_id', $this->businessId)->exists()) {
            return;
        }

        foreach ($this->data('production') as [$date, $product, $outputKg, $consumptions]) {
            $batch = new ProductionBatch([
                'business_id' => $this->businessId,
                'uuid' => (string) Str::uuid(),
                'product_id' => $this->products[$product],
                'batch_date' => $date,
                'output_kg' => $outputKg,
            ]);
            $batch->setConnection(self::CONNECTION);
            $batch->created_by = $this->ownerId;
            $batch->save();

            foreach ($consumptions as [$material, $qty]) {
                MaterialConsumption::on(self::CONNECTION)->create([
                    'business_id' => $this->businessId,
                    'production_batch_id' => $batch->id,
                    'raw_material_id' => $this->materials[$material],
                    'qty' => $qty,   // positive amount consumed
                ]);

                $movement = new StockMovement([
                    'business_id' => $this->businessId,
                    'uuid' => (string) Str::uuid(),
                    'raw_material_id' => $this->materials[$material],
                    'movement_date' => $date,
                    'kind' => 'out',
                    // Signed negative, or it would RAISE stock.
                    'qty' => bcmul($qty, '-1', 3),
                    'production_batch_id' => $batch->id,
                ]);
                $movement->setConnection(self::CONNECTION);
                $movement->created_by = $this->ownerId;
                $movement->save();
            }
        }
    }
```

- [x] **Step 4: Run test to verify it passes**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php
```

Expected: PASS, 13 tests.

- [x] **Step 5: Commit**

```bash
cd backend && git add database/seeders/ShreeRajShyamajiSeeder.php tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php
git commit -m "feat: seed reconstructed production with its stock movements"
```

---

### Task 13: Delete `DemoDataSeeder` and wire the new one in

**Files:**
- Delete: `backend/database/seeders/DemoDataSeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Test: `backend/tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php`

- [x] **Step 1: Write the failing test**

Append:

```php
it('is idempotent, so a second db:seed does not double the books', function () {
    $before = [
        Customer::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Sale::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        SaleLine::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Payment::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Purchase::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        ProductionBatch::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
    ];

    $this->seed(ShreeRajShyamajiSeeder::class);   // beforeEach already ran it once

    expect([
        Customer::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Sale::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        SaleLine::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Payment::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Purchase::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        ProductionBatch::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
    ])->toBe($before);
});

it('leaves no demo tenant behind', function () {
    expect(Business::on('pgsql_migrate')->whereIn('name', [
        'Demo Namkeen Bhandar', 'Demo Sweets House',
    ])->count())->toBe(0);
});
```

- [x] **Step 2: Run test to verify it fails**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php --filter="idempotent"
```

Expected: FAIL if any guard is missing — duplicated counts on the second run.
(The `no demo tenant` test may already pass, since this test seeds only the new
seeder; it is there to catch a regression once `DatabaseSeeder` is rewired.)

- [x] **Step 3: Delete the demo seeder and rewire**

```bash
cd backend && git rm database/seeders/DemoDataSeeder.php
```

Replace `backend/database/seeders/DatabaseSeeder.php` entirely:

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development data: the platform superadmin, then the one real
     * tenant.
     *
     * Writes go through the privileged pgsql_migrate connection: the seeder
     * runs outside a request, so no SetTenantContext transaction has set
     * app.current_tenant, and the memberships RLS WITH CHECK would reject the
     * insert on the restricted app connection.
     */
    public function run(): void
    {
        $this->platformAdmin();

        $this->call(ShreeRajShyamajiSeeder::class);

        $this->command->info('Seeded owner@vyaparbook.test / password123');
        $this->command->info('Superadmin: admin@vyaparbook.test / password123');
    }

    /**
     * The admin console needs a superadmin to log into. Infrastructure rather
     * than demo business data, which is why it outlived DemoDataSeeder.
     */
    private function platformAdmin(): void
    {
        $admin = User::on('pgsql_migrate')->updateOrCreate(
            ['email' => 'admin@vyaparbook.test'],
            ['name' => 'Platform Admin', 'phone' => '9800000000', 'password' => Hash::make('password123')],
        );

        $admin->setConnection('pgsql_migrate');
        $admin->is_platform_admin = true;
        $admin->save();
    }
}
```

- [x] **Step 4: Run the seeder test, then the full suite**

```bash
cd backend && ./vendor/bin/pest tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php
```

Expected: PASS, 15 tests.

```bash
cd backend && ./vendor/bin/pest
```

Expected: PASS, no failures. If anything referenced `DemoDataSeeder`, it surfaces
here — the only known reference was `DatabaseSeeder`, rewired above.

- [x] **Step 5: Run the seeder for real against the dev database**

```bash
cd backend && php artisan migrate:fresh --seed --database=pgsql_migrate --force
```

`--database=pgsql_migrate` is required, not optional: the default `pgsql`
connection uses the restricted `vyaparbook_app` role, which does not own the
tables, so a plain `migrate:fresh` dies with "must be owner of table
beat_customers". This is the same reason `RefreshesTenantDatabase` passes the
flag.

Expected output includes:
```
Shree Raj Shyama Ji Namkeen: seeded catalog and masters.
Seeded owner@vyaparbook.test / password123
Superadmin: admin@vyaparbook.test / password123
```

Then sanity-check the dashboard renders real figures:

```bash
cd backend && php artisan tinker --execute='
$b = App\Models\Business::on("pgsql_migrate")->where("name", "Shree Raj Shyama Ji Namkeen")->first();
echo "customers: ".App\Models\Customer::on("pgsql_migrate")->where("business_id",$b->id)->count()."\n";
echo "sales:     ".App\Models\Sale::on("pgsql_migrate")->where("business_id",$b->id)->count()."\n";
echo "purchases: ".App\Models\Purchase::on("pgsql_migrate")->where("business_id",$b->id)->count()."\n";
'
```

Expected: `customers: 40`, `sales: 59`, `purchases: 23`.

- [x] **Step 6: Commit**

```bash
cd backend && git add -A database/seeders tests/Feature/Seeders
git commit -m "feat: replace the demo tenants with the real Shree Raj Shyama Ji data"
```

---

## Done criteria

- [x] `./vendor/bin/pest` passes with no failures.
- [x] `php artisan migrate:fresh --seed --database=pgsql_migrate` completes and
      reports the new seeder.
- [x] The only business in the database is `Shree Raj Shyama Ji Namkeen`.
- [x] `/reports/dashboard` shows ₹42,894 customer outstanding and a positive
      gross profit on all three products.
- [x] `/reminders` lists real overdue customers (Ghore lal ₹9,365 largest), each
      blocked as `no_phone`, which is expected until phone numbers arrive.
