# Negotiated Sale Pricing & Ledger Line Items — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a salesman change a sale line's price in the field — prefilled from the pack default, bounded by a cost floor — and show the items (at the price actually charged) under each sale in the customer's khata ledger.

**Architecture:** The floor rule is a pure function implemented twice (PHP + JS) and tested against the same case table, because it must be enforced on the phone (so the salesman can renegotiate) and on the server (because a rule enforced only on a client is not enforced). `rate` becomes client-supplied; `list_rate` is always computed server-side so the discount record cannot be faked.

**Tech Stack:** PHP 8.3 / Laravel 11, PostgreSQL, Pest; React + Dexie, Vitest (`fake-indexeddb`).

**Spec:** `docs/superpowers/specs/2026-07-25-negotiated-sale-pricing-design.md`

---

## Before you start

```bash
git checkout master && git pull
git checkout -b feat/negotiated-sale-pricing
```

- App root is `backend/`. Run every command from there.
- Local services (Postgres/PgBouncer/Redis) must be running. If the suite cannot connect, ask the user to start them (WSL sudo — only they can).
- Record the green baseline:

```bash
cd backend && php artisan test        # expect 698 passed
npx vitest run                        # expect 119 passed
```

### Conventions

- Money is a **scale-2 decimal string**, never a float. Add with `bcadd`, multiply with `bcmul`, compare with `bccomp`.
- In JS, money is **integer paise** via `toPaise()` from `offline/money.js`.
- Tests write fixtures on the `pgsql_migrate` connection (bypasses RLS), then read through the tenant pin.
- `created_by` and `total` are never fillable; they are stamped explicitly.
- Assertions that read rows **outside** a tenant-pinned closure must query `DB::connection('pgsql_migrate')->table(...)` directly — `BelongsToTenant` hides them otherwise.

### Scope decisions locked from the spec (do not re-litigate)

- `rate` is client-supplied; **`list_rate` is server-authored** and a client-sent value is ignored.
- Floor = `default_cost_price` ?? `base_cost_per_kg × weight_kg` ?? none.
- **`rate == floor` is allowed**; only `rate < floor` is rejected.
- The **derived floor rounds UP** to the paisa.
- The floor is checked on the rate alone, **independent of qty sign**.
- `list_rate` is nullable and **never backfilled**.
- Void copies both rates **unchanged**, negating only qty and line_total.
- No discount badge, no percentage-band floor, no report changes.

---

## File structure

**Create:**
- `app/Pricing/PriceFloor.php` — the floor rule (PHP).
- `tests/Unit/PriceFloorTest.php`
- `resources/js/offline/pricing.js` — the floor rule (JS) + a `belowFloor` check.
- `resources/js/offline/pricing.test.js`
- `resources/js/offline/lineItems.js` — pure formatting of sale lines for display.
- `resources/js/offline/lineItems.test.js`
- `database/migrations/2026_07_25_000013_add_list_rate_to_sale_lines.php`

**Modify:**
- `app/Services/LedgerWriter.php` — accept a rate, author `list_rate`, enforce the floor.
- `app/Models/SaleLine.php` — cast `list_rate`.
- `app/Http/Controllers/Api/V1/SaleController.php` — void copies `list_rate`.
- `resources/js/screens/Forms.jsx` — rate field, subtotal, floor error, total from rates.
- `resources/js/main.jsx` — carry `rate` in the outbox payload.
- `resources/js/offline/khata.js` — attach items to sale entries.
- `resources/js/screens/CustomerLedger.jsx` — render items.
- `resources/js/i18n.js` — new UI strings.
- `tests/Feature/Khata/SaleTest.php`, `tests/Feature/Sync/SyncPushTest.php`, `resources/js/offline/khata.test.js`

---

## Task 1: `PriceFloor` (PHP, pure, TDD)

**Files:**
- Create: `app/Pricing/PriceFloor.php`, `tests/Unit/PriceFloorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/PriceFloorTest.php

use App\Models\Business;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Pricing\PriceFloor;

/**
 * THE SHARED CASE TABLE. resources/js/offline/pricing.test.js asserts the same
 * cases against the JS implementation. A case added here must be added there —
 * the two files are one contract, and drift between them is the failure mode
 * this design accepts in exchange for enforcing the floor offline.
 */
function floorPack(?string $cost, ?string $perKg, string $weight = '0.500'): ProductPack
{
    $business = Business::factory()->create();

    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $product->base_cost_per_kg = $perKg;
    $product->save();

    $size = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => 'P', 'weight_kg' => $weight,
    ]);

    $pack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '100.00',
        'default_cost_price' => $cost,
    ]);

    return $pack->load(['product', 'packSize']);
}

it('uses the pack cost price when it is set', function () {
    expect(PriceFloor::for(floorPack('92.00', '180.00')))->toBe('92.00');
});

it('derives the floor from cost-per-kg and pack weight when no cost price is set', function () {
    // 180.00 per kg x 0.500 kg = 90.00
    expect(PriceFloor::for(floorPack(null, '180.00', '0.500')))->toBe('90.00');
});

it('rounds a derived floor UP to the paisa, so it never sits below true cost', function () {
    // 181.00 x 0.333 = 60.273 -> 60.28
    expect(PriceFloor::for(floorPack(null, '181.00', '0.333')))->toBe('60.28');
});

it('does not round up a derived floor that is already exact', function () {
    // 180.00 x 0.333 = 59.940
    expect(PriceFloor::for(floorPack(null, '180.00', '0.333')))->toBe('59.94');
});

it('treats a zero cost price as a real floor of zero, not as missing', function () {
    expect(PriceFloor::for(floorPack('0.00', '180.00')))->toBe('0.00');
});

it('returns null when there is neither a cost price nor a cost per kg', function () {
    expect(PriceFloor::for(floorPack(null, null)))->toBeNull();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Unit/PriceFloorTest.php`
Expected: FAIL — class `App\Pricing\PriceFloor` not found.

- [ ] **Step 3: Implement `app/Pricing/PriceFloor.php`**

```php
<?php
// app/Pricing/PriceFloor.php

namespace App\Pricing;

use App\Models\ProductPack;

/**
 * The lowest price a line may be sold at.
 *
 * Pure and DB-free apart from reading the pack it is handed, because the phone
 * must reach the identical answer offline — see resources/js/offline/pricing.js,
 * which implements this same rule. The two are tested against one shared case
 * table; a change to either must be mirrored.
 *
 * Returns a scale-2 decimal string, or null when the pack has no cost basis at
 * all, in which case the line is unbounded (stated in the spec, not silently
 * permissive).
 */
final class PriceFloor
{
    public static function for(ProductPack $pack): ?string
    {
        $cost = $pack->default_cost_price;

        // A zero cost is a real floor of zero; only null/'' means "not set".
        if ($cost !== null && trim((string) $cost) !== '') {
            return bcadd((string) $cost, '0', 2);
        }

        $perKg = $pack->product?->base_cost_per_kg;
        $weightKg = $pack->packSize?->weight_kg;

        if ($perKg === null || $weightKg === null) {
            return null;
        }

        // decimal(10,2) x decimal(8,3) is exact at scale 5; scale 6 is headroom.
        return self::ceilToPaisa(bcmul((string) $perKg, (string) $weightKg, 6));
    }

    /** Round UP to the paisa so the floor never lands below true cost. */
    private static function ceilToPaisa(string $value): string
    {
        $truncated = bcadd($value, '0', 2);   // bcadd truncates toward zero

        return bccomp($truncated, $value, 6) === 0 ? $truncated : bcadd($truncated, '0.01', 2);
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/pest tests/Unit/PriceFloorTest.php`
Expected: PASS (6 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Pricing/PriceFloor.php tests/Unit/PriceFloorTest.php
git commit -m "feat: add PriceFloor, the cost floor for a negotiated sale line"
```

---

## Task 2: `pricing.js` (JS, pure, the same case table)

**Files:**
- Create: `resources/js/offline/pricing.js`, `resources/js/offline/pricing.test.js`

- [ ] **Step 1: Write the failing test**

```js
import { describe, expect, it } from 'vitest';
import { belowFloor, floorPaise } from './pricing';

/**
 * THE SHARED CASE TABLE — the mirror of tests/Unit/PriceFloorTest.php.
 * A case added there must be added here. Values are in paise.
 */
const pack = (over = {}) => ({
    default_cost_price: null,
    weight_kg: '0.500',
    ...over,
});

const product = (over = {}) => ({ base_cost_per_kg: null, ...over });

describe('floorPaise', () => {
    it('uses the pack cost price when it is set', () => {
        expect(floorPaise(pack({ default_cost_price: '92.00' }), product({ base_cost_per_kg: '180.00' })))
            .toBe(9200);
    });

    it('derives the floor from cost-per-kg and pack weight when no cost price is set', () => {
        expect(floorPaise(pack({ weight_kg: '0.500' }), product({ base_cost_per_kg: '180.00' })))
            .toBe(9000);
    });

    it('rounds a derived floor UP to the paisa', () => {
        // 181.00 x 0.333 = 60.273 -> 6028 paise
        expect(floorPaise(pack({ weight_kg: '0.333' }), product({ base_cost_per_kg: '181.00' })))
            .toBe(6028);
    });

    it('does not round up a derived floor that is already exact', () => {
        expect(floorPaise(pack({ weight_kg: '0.333' }), product({ base_cost_per_kg: '180.00' })))
            .toBe(5994);
    });

    it('treats a zero cost price as a real floor of zero, not as missing', () => {
        expect(floorPaise(pack({ default_cost_price: '0.00' }), product({ base_cost_per_kg: '180.00' })))
            .toBe(0);
    });

    it('returns null when there is neither a cost price nor a cost per kg', () => {
        expect(floorPaise(pack(), product())).toBeNull();
    });

    it('returns null when the pack or product is missing entirely', () => {
        expect(floorPaise(undefined, product({ base_cost_per_kg: '180.00' }))).toBeNull();
        expect(floorPaise(pack({ weight_kg: '0.500' }), undefined)).toBeNull();
    });
});

describe('belowFloor', () => {
    it('rejects a rate under the floor', () => {
        expect(belowFloor(8999, 9000)).toBe(true);
    });

    it('allows a rate exactly at the floor — selling at cost is a real decision', () => {
        expect(belowFloor(9000, 9000)).toBe(false);
    });

    it('allows anything when there is no floor', () => {
        expect(belowFloor(1, null)).toBe(false);
    });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx vitest run resources/js/offline/pricing.test.js`
Expected: FAIL — cannot resolve `./pricing`.

- [ ] **Step 3: Implement `resources/js/offline/pricing.js`**

```js
/**
 * The lowest price a sale line may be sold at.
 *
 * This mirrors App\Pricing\PriceFloor on the server, deliberately: the floor
 * has to be enforced on the phone so a salesman can renegotiate while the
 * customer is still standing there, and on the server because a rule enforced
 * only on a client is not enforced. The two are tested against one shared case
 * table — see pricing.test.js and tests/Unit/PriceFloorTest.php.
 *
 * Works in integer paise throughout; money never touches a float.
 */

import { toPaise } from './money';

/** The floor in paise, or null when the pack has no cost basis at all. */
export function floorPaise(pack, product) {
    const cost = pack?.default_cost_price;

    // A zero cost is a real floor of zero; only null/undefined/'' means unset.
    if (cost !== null && cost !== undefined && String(cost).trim() !== '') {
        return toPaise(cost);
    }

    const perKg = product?.base_cost_per_kg;
    const weightKg = pack?.weight_kg;

    if (perKg === null || perKg === undefined || weightKg === null || weightKg === undefined) {
        return null;
    }

    // Integer arithmetic: weight has 3 decimals, so scale it to whole grams
    // rather than multiplying by a fraction and inheriting float error.
    const grams = Math.round(Number(weightKg) * 1000);

    return Math.ceil((toPaise(perKg) * grams) / 1000);
}

/** True when the rate is under the floor. Equal to the floor is allowed. */
export function belowFloor(ratePaise, floor) {
    if (floor === null || floor === undefined) return false;

    return ratePaise < floor;
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `npx vitest run resources/js/offline/pricing.test.js`
Expected: PASS (10 passing).

- [ ] **Step 5: Commit**

```bash
git add resources/js/offline/pricing.js resources/js/offline/pricing.test.js
git commit -m "feat: mirror the price floor on the client for offline enforcement"
```

---

## Task 3: Schema + `LedgerWriter` accepts a rate

**Files:**
- Create: `database/migrations/2026_07_25_000013_add_list_rate_to_sale_lines.php`
- Modify: `app/Models/SaleLine.php`, `app/Services/LedgerWriter.php`, `app/Http/Controllers/Api/V1/SaleController.php`
- Test: `tests/Feature/Khata/SaleTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_07_25_000013_add_list_rate_to_sale_lines.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the pack's default price WAS on the day of the sale.
 *
 * Nullable and never backfilled: rows written before this shipped genuinely
 * have no such value, and inventing one from today's default would make future
 * discount analysis wrong while looking authoritative. Null means unknown.
 *
 * Server-authored — never accepted from the client, or a phone could claim it
 * sold at list while charging less.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->table('sale_lines', function (Blueprint $table) {
            $table->decimal('list_rate', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->table('sale_lines', function (Blueprint $table) {
            $table->dropColumn('list_rate');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `2026_07_25_000013_add_list_rate_to_sale_lines ... DONE`

- [ ] **Step 3: Cast it on the model**

In `app/Models/SaleLine.php`, add to `$casts` (leave `$fillable` alone — `list_rate` is stamped, never filled):

```php
        'list_rate' => 'decimal:2',
```

- [ ] **Step 4: Write the failing tests (append to `tests/Feature/Khata/SaleTest.php`)**

`saleSetup()` and `postSale()` already exist in that file; reuse them.

```php
it('honours a negotiated rate from the client', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '80.00'],
    ])->assertCreated();

    $line = DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->sole();

    expect((string) $line->rate)->toBe('80.00');
    expect((string) $line->line_total)->toBe('160.00');
    // Server-authored, and it records what list WAS that day.
    expect((string) $line->list_rate)->toBe('90.00');
});

it('falls back to the default when no rate is sent, so older clients still work', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1],
    ])->assertCreated();

    $line = DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->sole();

    expect((string) $line->rate)->toBe('90.00');
    expect((string) $line->list_rate)->toBe('90.00');
});

it('ignores a client-sent list_rate, so a discount cannot be faked', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '80.00', 'list_rate' => '80.00'],
    ])->assertCreated();

    $line = DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->sole();

    expect((string) $line->list_rate)->toBe('90.00');   // the server's, not the client's
});

it('refuses a rate below the pack cost floor', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');
    DB::connection('pgsql_migrate')->table('product_packs')
        ->where('id', $pack->id)->update(['default_cost_price' => '70.00']);

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '69.99'],
    ])->assertStatus(422);

    expect(DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->count())->toBe(0);
});

it('allows a rate exactly at the floor', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');
    DB::connection('pgsql_migrate')->table('product_packs')
        ->where('id', $pack->id)->update(['default_cost_price' => '70.00']);

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '70.00'],
    ])->assertCreated();
});

it('allows a rate above list — negotiating upward is legitimate', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '120.00'],
    ])->assertCreated();

    $line = DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->sole();

    expect((string) $line->rate)->toBe('120.00');
    expect((string) $line->list_rate)->toBe('90.00');
});

it('voids a sale by copying both rates unchanged and negating only qty and total', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');

    $created = postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '80.00'],
    ])->assertCreated();

    test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/sales/'.$created->json('id').'/void')
        ->assertCreated();

    $reversal = DB::connection('pgsql_migrate')->table('sale_lines')
        ->where('business_id', $business->id)->where('qty', -2)->sole();

    // The price is mirrored, not re-derived: today's default may have moved.
    expect((string) $reversal->rate)->toBe('80.00');
    expect((string) $reversal->list_rate)->toBe('90.00');
    expect((string) $reversal->line_total)->toBe('-160.00');
});

it('rejects a negative rate — a return is a negative qty, not a negative price', function () {
    [, $token, $customer, $pack] = saleSetup('salesman', '90.00');

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '-5.00'],
    ])->assertStatus(422);
});

it('applies the floor to a return line too, independent of the qty sign', function () {
    [$business, $token, $customer, $pack] = saleSetup('salesman', '90.00');
    DB::connection('pgsql_migrate')->table('product_packs')
        ->where('id', $pack->id)->update(['default_cost_price' => '70.00']);

    postSale($token, $customer, [
        ['product_pack_id' => $pack->id, 'qty' => -1, 'rate' => '60.00'],
    ])->assertStatus(422);
});
```

Add `use Illuminate\Support\Facades\DB;` to the file's imports if it is not already there.

- [ ] **Step 5: Run them and watch them fail**

Run: `./vendor/bin/pest tests/Feature/Khata/SaleTest.php`
Expected: FAIL — the rate is ignored, `list_rate` is null, below-floor sales are accepted.

- [ ] **Step 6: Accept the rate in `rulesForSale`**

In `app/Services/LedgerWriter.php`, add one rule to `rulesForSale()`:

```php
            // Optional: omitted means "use the shop's default", which keeps the
            // REST endpoint and any older client working unchanged.
            'lines.*.rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
```

- [ ] **Step 7: Author `list_rate` and enforce the floor in `createSale`**

Replace the rate-freezing loop inside `createSale` (currently the `foreach ($data['lines'] as $line)` block that calls `snapshotRate`) with:

```php
            foreach ($data['lines'] as $line) {
                $pack = ProductPack::findOrFail($line['product_pack_id']);

                // list_rate is ALWAYS the server's own answer. Accepting it from
                // the client would let a phone claim it sold at list while
                // charging less, making the discount record fiction.
                $listRate = $this->khata->snapshotRate($pack);
                $rate = isset($line['rate']) ? bcadd((string) $line['rate'], '0', 2) : $listRate;

                $floor = PriceFloor::for($pack->load(['product', 'packSize']));

                // Checked on the rate alone: a return is a negative qty at a
                // positive rate, bounded by the same rule as the sale it reverses.
                if ($floor !== null && bccomp($rate, $floor, 2) < 0) {
                    throw ValidationException::withMessages([
                        'lines' => __('Rate for :product cannot be below :floor.', [
                            'product' => $pack->product?->name_en ?: $pack->product?->name_hi ?: 'this product',
                            'floor' => $floor,
                        ]),
                    ]);
                }

                $lineTotal = bcmul($rate, (string) $line['qty'], 2);

                $lines[] = [
                    'product_pack_id' => $pack->id,
                    'qty' => $line['qty'],
                    'rate' => $rate,
                    'list_rate' => $listRate,
                    'line_total' => $lineTotal,
                ];
                $total = bcadd($total, $lineTotal, 2);
            }
```

And stamp it when the row is written — `list_rate` is not fillable, so assign it:

```php
                $saleLine = new SaleLine([
                    'business_id' => app('tenant.id'),
                    'sale_id' => $sale->id,
                    'product_pack_id' => $l['product_pack_id'],
                    'qty' => $l['qty'],
                    'rate' => $l['rate'],
                ]);
                $saleLine->list_rate = $l['list_rate'];
                $saleLine->line_total = $l['line_total'];
                $saleLine->save();
```

Add the imports at the top of `LedgerWriter.php`:

```php
use App\Pricing\PriceFloor;
use Illuminate\Validation\ValidationException;
```

- [ ] **Step 8: Carry `list_rate` through a void**

In `app/Http/Controllers/Api/V1/SaleController.php`, inside the `foreach ($original->lines as $line)` loop of `void()`, add the copy — **unchanged, not negated**, exactly as `rate` is already treated:

```php
                $r->list_rate = $line->list_rate;
```

Place it immediately before `$r->line_total = bcmul((string) $line->line_total, '-1', 2);`.

- [ ] **Step 9: Run them and watch them pass**

Run: `./vendor/bin/pest tests/Feature/Khata/SaleTest.php`
Expected: PASS (all, including the seven new cases).

- [ ] **Step 10: Commit**

```bash
git add database/migrations app/Models/SaleLine.php app/Services/LedgerWriter.php \
        app/Http/Controllers/Api/V1/SaleController.php tests/Feature/Khata/SaleTest.php
git commit -m "feat: accept a negotiated rate, author list_rate, enforce the cost floor"
```

---

## Task 4: A below-floor push parks without killing its batch

**Files:**
- Test: `tests/Feature/Sync/SyncPushTest.php`

- [ ] **Step 1: Note the fixtures already in that file**

`tests/Feature/Sync/SyncPushTest.php` defines `syncSetup(string $role = 'owner'): array` returning `[$business, $token, $customer]` — **no product pack** — and `push(string $token, array $mutations)`. Reuse both; the test below seeds its own pack because `syncSetup` does not.

- [ ] **Step 2: Write the test (append to that file)**

```php
it('rejects only the below-floor line and still applies the rest of the batch', function () {
    [$business, $token, $customer] = syncSetup();

    // syncSetup has no catalog, so build one with a cost floor of 70.00.
    $product = App\Models\Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $size = App\Models\PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $pack = App\Models\ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '90.00',
        'default_cost_price' => '70.00',
    ]);

    $good = (string) Str::uuid();
    $bad = (string) Str::uuid();

    $saleMutation = fn (string $uuid, string $rate) => [
        'type' => 'sale', 'tenant_id' => $business->id, 'uuid' => $uuid,
        'payload' => [
            'uuid' => $uuid, 'customer_id' => $customer->id, 'sale_date' => '2026-07-20',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => $rate]],
        ],
    ];

    push($token, [$saleMutation($good, '80.00'), $saleMutation($bad, '10.00')])->assertOk();

    // The legitimate sale survives its neighbour's rejection.
    expect(DB::connection('pgsql_migrate')->table('sales')
        ->where('business_id', $business->id)->where('uuid', $good)->count())->toBe(1);
    expect(DB::connection('pgsql_migrate')->table('sales')
        ->where('business_id', $business->id)->where('uuid', $bad)->count())->toBe(0);
});
```

Add `use Illuminate\Support\Facades\DB;` to that file's imports if it is not already there.

- [ ] **Step 3: Run it**

Run: `./vendor/bin/pest tests/Feature/Sync/SyncPushTest.php`
Expected: PASS without further code changes — the per-item savepoint already isolates the failure. If it FAILS because the whole batch aborts, that is a real defect in the push loop: fix it there, in `SyncController::push`, so one bad mutation cannot take its neighbours down.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Sync/SyncPushTest.php
git commit -m "test: a below-floor sale parks without aborting its push batch"
```

---

## Task 5: The rate field on Record Sale

**Files:**
- Modify: `resources/js/screens/Forms.jsx`, `resources/js/main.jsx`, `resources/js/i18n.js`

- [ ] **Step 1: Add the UI strings**

In `resources/js/i18n.js`, add to **both** the `en` and `hi` string maps:

```js
        rate: 'Rate',
        below_floor: 'Below cost — lowest is',
        subtotal: 'Subtotal',
```

```js
        rate: 'दर',
        below_floor: 'लागत से कम — न्यूनतम है',
        subtotal: 'उप-योग',
```

- [ ] **Step 2: Give each line a rate and compute from it**

In `resources/js/screens/Forms.jsx`, `RecordSale`:

Change the initial line state so a rate travels with each line:

```js
    const [lines, setLines] = useState([{ product_pack_id: '', qty: '1', rate: '' }]);
```

Change the "+ Add line" handler the same way:

```js
                    onClick={() => setLines((l) => [...l, { product_pack_id: '', qty: '1', rate: '' }])}
```

Replace `setLine` so choosing a product **re-fills the rate** from that pack's default. A rate typed for the previous product is meaningless against the new one:

```js
    const setLine = (index, key) => (e) =>
        setLines((current) =>
            current.map((line, i) => {
                if (i !== index) return line;

                const next = { ...line, [key]: e.target.value };

                if (key === 'product_pack_id') {
                    const pack = packs.find((p) => p.id === e.target.value);
                    next.rate = pack ? String(pack.default_sell_price) : '';
                }

                return next;
            })
        );
```

Replace the total so it uses the line's rate, not the pack default — otherwise a negotiated price would be silently ignored in the figure the salesman reads out:

```js
    const linePaise = (line) => {
        const qty = Number(line.qty);
        const rate = toPaise(line.rate || '0');

        return Number.isFinite(qty) ? rate * qty : 0;
    };

    const totalPaise = lines.reduce((sum, line) => sum + (line.product_pack_id ? linePaise(line) : 0), 0);
```

- [ ] **Step 3: Block a below-floor line**

Still in `RecordSale`, add the floor check and use it to gate submit:

```js
    const floorFor = (line) => {
        const pack = packs.find((p) => p.id === line.product_pack_id);

        return pack ? floorPaise(pack, productsById.get(pack.product_id)) : null;
    };

    const violations = lines.map((line) =>
        line.product_pack_id && belowFloor(toPaise(line.rate || '0'), floorFor(line))
            ? floorFor(line)
            : null
    );
```

In `submit`, refuse before saving:

```js
        if (violations.some((v) => v !== null)) {
            setError(t('below_floor'));
            return;
        }
```

Add the imports at the top of `Forms.jsx`:

```js
import { belowFloor, floorPaise } from '../offline/pricing';
```

- [ ] **Step 4: Render the rate, subtotal and error**

Replace the qty `<div className="w-20 shrink-0">…</div>` block with a row carrying qty, rate and subtotal, and the inline violation message:

```jsx
                        <div className="w-16 shrink-0">
                            <label htmlFor={`qty-${index}`} className="field-label">{t('qty')}</label>
                            <input
                                id={`qty-${index}`}
                                inputMode="numeric"
                                className="field-input tabular"
                                value={line.qty}
                                onChange={setLine(index, 'qty')}
                                data-testid={`sale-qty-${index}`}
                            />
                        </div>

                        <div className="w-24 shrink-0">
                            <label htmlFor={`rate-${index}`} className="field-label">{t('rate')}</label>
                            <input
                                id={`rate-${index}`}
                                inputMode="decimal"
                                className="field-input tabular"
                                value={line.rate}
                                onChange={setLine(index, 'rate')}
                                data-testid={`sale-rate-${index}`}
                            />
                        </div>
```

And immediately after the closing `</div>` of that line's row, inside the same `lines.map`:

```jsx
                    {violations[index] !== null && (
                        <p className="field-error" data-testid={`sale-floor-${index}`}>
                            {t('below_floor')} {formatRupees(violations[index])}
                        </p>
                    )}
```

- [ ] **Step 5: Send the rate**

`RecordSale`'s `submit` already maps valid lines; include the rate so the server receives it:

```js
                lines: valid.map((l) => ({
                    product_pack_id: l.product_pack_id,
                    qty: Number(l.qty),
                    rate: l.rate,
                })),
```

`main.jsx`'s `saveSale` passes `lines` straight into the outbox payload, so no change is needed there — verify by reading it.

- [ ] **Step 6: Verify**

Run: `npx vitest run` — expect all green (no new tests here; this is UI, and the repo has no component-test tooling).
Run: `npx vite build` — expect a clean build, then `git checkout -- public/build` to drop the artifacts.

- [ ] **Step 7: Commit**

```bash
git add resources/js/screens/Forms.jsx resources/js/main.jsx resources/js/i18n.js
git commit -m "feat: editable per-line rate on Record Sale, blocked below the cost floor"
```

---

## Task 6: Item lines in the customer ledger

**Files:**
- Create: `resources/js/offline/lineItems.js`, `resources/js/offline/lineItems.test.js`
- Modify: `resources/js/offline/khata.js`, `resources/js/screens/CustomerLedger.jsx`
- Test: `resources/js/offline/khata.test.js`

- [ ] **Step 1: Write the failing test for the pure formatter**

```js
// resources/js/offline/lineItems.test.js
import { describe, expect, it } from 'vitest';
import { describeLines } from './lineItems';

const packs = [{ id: 'k1', product_id: 'p1', label: '1kg' }];
const products = [{ id: 'p1', name_en: 'Sev Mix', name_hi: 'सेव मिक्स' }];

describe('describeLines', () => {
    it('describes a line with its product, size, qty and the rate charged', () => {
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: 3, rate: '105.00' }], packs, products, 'en'
        );

        expect(item.description).toBe('Sev Mix 1kg');
        expect(item.qty).toBe(3);
        expect(item.ratePaise).toBe(10500);
        expect(item.subtotalPaise).toBe(31500);
    });

    it('uses the reader\'s language for the product name', () => {
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: 1, rate: '105.00' }], packs, products, 'hi'
        );

        expect(item.description).toBe('सेव मिक्स 1kg');
    });

    it('keeps a return line negative so a void reads as real', () => {
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: -2, rate: '105.00' }], packs, products, 'en'
        );

        expect(item.qty).toBe(-2);
        expect(item.subtotalPaise).toBe(-21000);
    });

    it('still renders a line whose pack is not cached, rather than dropping it', () => {
        const [item] = describeLines(
            [{ product_pack_id: 'gone', qty: 1, rate: '10.00' }], packs, products, 'en'
        );

        // Dropping it would understate the sale; an unnamed line is honest.
        expect(item.description).toBe('');
        expect(item.subtotalPaise).toBe(1000);
    });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx vitest run resources/js/offline/lineItems.test.js`
Expected: FAIL — cannot resolve `./lineItems`.

- [ ] **Step 3: Implement `resources/js/offline/lineItems.js`**

```js
/**
 * Turning sale lines into something a human reads.
 *
 * Pure: the Dexie reads live in khata.js, so this can be tested directly — the
 * repo has no component-test tooling, and display logic that only exists inside
 * a component cannot be covered at all.
 */

import { productName } from './catalog';
import { toPaise } from './money';

/**
 * @param lines    rows shaped like sale_lines, or an outbox payload's lines
 * @param packs    cached product_packs
 * @param products cached products
 * @param locale   the reader's language
 */
export function describeLines(lines, packs, products, locale = 'en') {
    const packsById = new Map(packs.map((p) => [p.id, p]));
    const productsById = new Map(products.map((p) => [p.id, p]));

    return (lines ?? []).map((line) => {
        const pack = packsById.get(line.product_pack_id);
        const name = pack ? productName(productsById.get(pack.product_id), locale) : '';
        const qty = Number(line.qty) || 0;
        const ratePaise = toPaise(line.rate ?? '0');

        return {
            // A line whose pack is no longer cached still shows its money —
            // dropping it would understate what the sale was.
            description: [name, pack?.label].filter(Boolean).join(' '),
            qty,
            ratePaise,
            subtotalPaise: ratePaise * qty,
        };
    });
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `npx vitest run resources/js/offline/lineItems.test.js`
Expected: PASS (4 passing).

- [ ] **Step 5: Attach items in `ledgerFor`**

In `resources/js/offline/khata.js`, import what is needed:

```js
import { describeLines } from './lineItems';
import { getLocale } from '../i18n';
```

Read the caches once inside `ledgerFor`, before building `entries`:

```js
    const packs = await db.product_packs.toArray();
    const products = await db.products.toArray();
    const allLines = await db.sale_lines.toArray();
    const locale = getLocale();
```

Where a **synced** sale entry is built, attach its items by `sale_id`:

```js
            items: describeLines(
                allLines.filter((l) => l.sale_id === s.id),
                packs, products, locale
            ),
```

Where a **queued** sale entry is built from the outbox, attach from the payload — the ledger already shows pending sales, and they would look broken with nothing beneath them:

```js
                    items: describeLines(entry.payload.lines, packs, products, locale),
```

Payment entries get `items: []` so the shape is uniform.

- [ ] **Step 6: Write the failing ledger test (append to `resources/js/offline/khata.test.js`)**

Follow the file's existing fixture helpers (`customer()`, `sale()`); seed packs, products and sale_lines with `bulkPut`, then:

```js
it('lists the items of a synced sale at the price charged', async () => {
    await db.customers.bulkPut([customer()]);
    await db.products.bulkPut([{ id: 'p1', name_en: 'Sev Mix', name_hi: 'सेव मिक्स' }]);
    await db.product_packs.bulkPut([{ id: 'k1', product_id: 'p1', label: '1kg' }]);
    await db.sales.bulkPut([sale({ uuid: 'sale-1', id: 'srv-sale-1', total: '210.00' })]);
    await db.sale_lines.bulkPut([
        { id: 'l1', sale_id: 'srv-sale-1', product_pack_id: 'k1', qty: 2, rate: '105.00' },
    ]);

    const entries = await ledgerFor(db, customer());
    const saleEntry = entries.find((e) => e.uuid === 'sale-1');

    expect(saleEntry.items).toHaveLength(1);
    expect(saleEntry.items[0].description).toBe('Sev Mix 1kg');
    expect(saleEntry.items[0].subtotalPaise).toBe(21000);
});

it('lists the items of a queued sale from its outbox payload', async () => {
    await db.customers.bulkPut([customer()]);
    await db.products.bulkPut([{ id: 'p1', name_en: 'Sev Mix', name_hi: 'सेव मिक्स' }]);
    await db.product_packs.bulkPut([{ id: 'k1', product_id: 'p1', label: '1kg' }]);
    await enqueue(db, {
        type: 'sale', tenantId: TENANT, uuid: 'queued-1',
        payload: {
            customer_id: 'srv-cust-1', sale_date: '2026-07-02', total: '105.00',
            lines: [{ product_pack_id: 'k1', qty: 1, rate: '105.00' }],
        },
    });

    const entries = await ledgerFor(db, customer());
    const queued = entries.find((e) => e.uuid === 'queued-1');

    expect(queued.pending).toBe(true);
    expect(queued.items[0].description).toBe('Sev Mix 1kg');
});
```

- [ ] **Step 7: Run and pass**

Run: `npx vitest run resources/js/offline/khata.test.js`
Expected: PASS.

- [ ] **Step 8: Render the items**

In `resources/js/screens/CustomerLedger.jsx`, inside the `<li>` for each entry, after the existing kind/date block, add:

```jsx
                            {entry.items?.length > 0 && (
                                <ul className="mt-1 space-y-0.5">
                                    {entry.items.map((item, i) => (
                                        <li key={i} className="flex justify-between text-xs text-ink-muted">
                                            <span>{item.description || '—'}</span>
                                            <span className="tabular">
                                                {item.qty} × {formatRupees(item.ratePaise)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
```

- [ ] **Step 9: Verify and commit**

Run: `npx vitest run` (all green) and `npx vite build` (clean), then `git checkout -- public/build`.

```bash
git add resources/js/offline/lineItems.js resources/js/offline/lineItems.test.js \
        resources/js/offline/khata.js resources/js/offline/khata.test.js \
        resources/js/screens/CustomerLedger.jsx
git commit -m "feat: show sale items and the price charged in the customer ledger"
```

---

## Task 7: Full suite, docs, wrap-up

- [ ] **Step 1: Run both suites**

```bash
php artisan test      # expect 698 baseline + the new PHP cases, 0 failures
npx vitest run        # expect 119 baseline + the new JS cases, 0 failures
```

- [ ] **Step 2: Update `docs/ui-backlog.md`**

Add an `F-12` row above `F-11` recording: the rate is now client-supplied and floored; `list_rate` is server-authored so a discount cannot be faked; the floor rule is duplicated in PHP and JS by necessity and tested against one shared case table; ledger sales now show their items for both synced and queued sales. Reference the spec path.

- [ ] **Step 3: Manual check (recommended)**

`php artisan serve`, log in as `salesman@demo-namkeen-bhandar.test` / `password123`, open a customer, record a sale: confirm the rate prefills, changing the product re-fills it, a below-cost rate blocks with the limit named, the total follows the edited rate, and the sale then appears in the ledger with its items.

- [ ] **Step 4: Commit and finish the branch**

```bash
git add docs/ui-backlog.md
git commit -m "docs: log negotiated sale pricing in ui-backlog"
```

Open a PR and squash-merge (`gh api` REST — `gh pr` subcommands fail on this repo).

---

## Self-review notes (traceability to the spec)

- Decision 1 (client rate, server `list_rate`) → Task 3 Steps 6–7, with the "ignores a client-sent list_rate" test.
- Decision 2 (both sides enforce) → Task 1 (PHP), Task 2 (JS), Task 5 Step 3 (form blocks), Task 4 (push parks).
- Decision 3 (floor formula + the three paisa-level rules) → the shared case table in Tasks 1 and 2; equality allowed and round-up each have their own test; qty-sign independence tested in Task 3 Step 4.
- Decision 4 (`list_rate` nullable, never backfilled) → Task 3 Step 1; no backfill anywhere in this plan.
- Decision 5 (items inline, two sources) → Task 6 Steps 5–8, one test per source.
- Decision 6 (no discount badge) → nothing in this plan renders a comparison.
- Void copies both rates unchanged → Task 3 Step 8, asserted by the void test in Step 4.
- Edge case "rate above list is allowed" → its own test in Task 3 Step 4.
- "What does not change" → no task touches GST, COGS, finished goods or any report.
