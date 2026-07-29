<?php
// tests/Feature/Orders/OrderedValuesBackfillTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Orders\OrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The migration that added ordered_qty/ordered_rate backfills exactly one case.
 * Getting the WHERE clause wrong is the failure that matters: too narrow only
 * loses data that reads as unknown, but too wide MANUFACTURES a record saying
 * an already-accepted order was never edited.
 */

/** A shop with one customer and one pack. Returns [business, user, customer, pack]. */
function backfillSetup(): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();

    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders', 'opening_balance' => '0.00',
    ]);
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $size = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $pack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '90.00',
    ]);

    return [$business, $user, $customer, $pack];
}

/** A line with no originals — the shape every row had before the migration. */
function legacyOrderLine(Business $b, User $u, Customer $c, ProductPack $pack, string $status): string
{
    $orderId = (string) Str::uuid();
    $lineId = (string) Str::uuid();

    DB::connection('pgsql_migrate')->table('orders')->insert([
        'id' => $orderId, 'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'order_date' => '2026-07-20', 'status' => $status,
        'total' => '170.00', 'created_by' => $u->id, 'sync_seq' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('pgsql_migrate')->table('order_lines')->insert([
        'id' => $lineId, 'business_id' => $b->id, 'order_id' => $orderId,
        'product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '85.00',
        'ordered_qty' => null, 'ordered_rate' => null,
        'line_total' => '170.00', 'sync_seq' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $lineId;
}

function runBackfill(): void
{
    // require (not require_once) re-evaluates the file, returning the
    // migration instance so the statement itself is exercised, not a copy.
    $migration = require database_path('migrations/2026_07_29_000001_add_ordered_qty_rate_to_order_lines.php');
    $migration->backfill();
}

function orderedPair(string $lineId): array
{
    $line = DB::connection('pgsql_migrate')->table('order_lines')->where('id', $lineId)->first();

    return [$line->ordered_qty, $line->ordered_rate === null ? null : (string) $line->ordered_rate];
}

it('fills a pending order from its own values, which cannot yet have been edited', function () {
    [$business, $user, $customer, $pack] = backfillSetup();

    $lineId = legacyOrderLine($business, $user, $customer, $pack, OrderStatus::PENDING);

    runBackfill();

    expect(orderedPair($lineId))->toBe([2, '85.00']);
});

it('leaves every decided order unknown, because its values may already be an edit', function () {
    [$business, $user, $customer, $pack] = backfillSetup();

    $lines = [];
    foreach ([OrderStatus::ACCEPTED, OrderStatus::PACKED, OrderStatus::DELIVERED,
        OrderStatus::REJECTED, OrderStatus::CANCELLED] as $status) {
        $lines[$status] = legacyOrderLine($business, $user, $customer, $pack, $status);
    }

    runBackfill();

    foreach ($lines as $status => $lineId) {
        expect(orderedPair($lineId))->toBe([null, null], "status {$status} must stay unknown");
    }
});

it('never overwrites an original it already has', function () {
    [$business, $user, $customer, $pack] = backfillSetup();

    $lineId = legacyOrderLine($business, $user, $customer, $pack, OrderStatus::PENDING);
    DB::connection('pgsql_migrate')->table('order_lines')->where('id', $lineId)
        ->update(['ordered_qty' => 10, 'ordered_rate' => '90.00']);

    runBackfill();

    expect(orderedPair($lineId))->toBe([10, '90.00']);
});
