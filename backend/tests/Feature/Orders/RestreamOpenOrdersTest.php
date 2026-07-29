<?php
// tests/Feature/Orders/RestreamOpenOrdersTest.php

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
 * Widening the pull does not reach backwards: the delta is `sync_seq > cursor`,
 * so an order accepted before the change sits below every colleague's cursor
 * and would never arrive. The migration lifts open ones over it.
 *
 * The failure that matters is silent — a missed order simply never shows up on
 * the colleague's phone — so the status filter is tested rather than trusted.
 */

/** @return array{0: string, 1: string} the order id and its line id */
function restreamOrder(Business $b, User $u, Customer $c, ProductPack $pack, string $status, int $seq): array
{
    $orderId = (string) Str::uuid();
    $lineId = (string) Str::uuid();

    DB::connection('pgsql_migrate')->table('orders')->insert([
        'id' => $orderId, 'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'order_date' => '2026-07-20', 'status' => $status,
        'total' => '180.00', 'created_by' => $u->id, 'sync_seq' => $seq,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('pgsql_migrate')->table('order_lines')->insert([
        'id' => $lineId, 'business_id' => $b->id, 'order_id' => $orderId,
        'product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '90.00',
        'ordered_qty' => 2, 'ordered_rate' => '90.00',
        'line_total' => '180.00', 'sync_seq' => $seq,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$orderId, $lineId];
}

function runRestream(): void
{
    $migration = require database_path('migrations/2026_07_29_000002_restream_open_orders_to_every_device.php');
    $migration->restream();
}

function seqOf(string $table, string $id): int
{
    return (int) DB::connection('pgsql_migrate')->table($table)->where('id', $id)->value('sync_seq');
}

function restreamSetup(): array
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

it('lifts every open order and its lines over a device that had already caught up', function () {
    [$b, $u, $c, $pack] = restreamSetup();

    $before = [];
    foreach ([OrderStatus::PENDING, OrderStatus::ACCEPTED, OrderStatus::PACKED] as $status) {
        $before[$status] = restreamOrder($b, $u, $c, $pack, $status, 10);
    }

    // A colleague whose cursor is already past everything above.
    $cursor = (int) DB::connection('pgsql_migrate')->selectOne(
        "SELECT last_value AS v FROM sync_seq_global"
    )->v;

    runRestream();

    foreach ($before as $status => [$orderId, $lineId]) {
        expect(seqOf('orders', $orderId))->toBeGreaterThan($cursor, "order {$status}");
        // Without the line, the colleague gets a delivery with nothing in it.
        expect(seqOf('order_lines', $lineId))->toBeGreaterThan($cursor, "line {$status}");
    }
});

it('leaves finished orders where they are, being history nobody can act on', function () {
    [$b, $u, $c, $pack] = restreamSetup();

    $done = [];
    foreach ([OrderStatus::DELIVERED, OrderStatus::REJECTED, OrderStatus::CANCELLED] as $status) {
        $done[$status] = restreamOrder($b, $u, $c, $pack, $status, 10);
    }

    runRestream();

    foreach ($done as $status => [$orderId, $lineId]) {
        expect(seqOf('orders', $orderId))->toBe(10, "order {$status}");
        expect(seqOf('order_lines', $lineId))->toBe(10, "line {$status}");
    }
});

it('keeps every line at or above its own order, so a line never outranks it', function () {
    // The delta is ordered by sync_seq. A line arriving in an earlier pull than
    // its order would be an orphan on the device until the next one.
    [$b, $u, $c, $pack] = restreamSetup();
    [$orderId, $lineId] = restreamOrder($b, $u, $c, $pack, OrderStatus::ACCEPTED, 10);

    runRestream();

    expect(seqOf('order_lines', $lineId))->toBeGreaterThan(seqOf('orders', $orderId));
});
