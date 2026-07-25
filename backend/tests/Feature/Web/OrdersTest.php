<?php
// tests/Feature/Web/OrdersTest.php

use App\Models\Customer;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Orders\OrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A pending order for the owner to act on. Returns [owner, business, orderId, pack]. */
function pendingOrder(string $rate = '85.00'): array
{
    [$owner, $business] = pwOwner();

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
        'default_cost_price' => '70.00',
    ]);

    $orderId = (string) Str::uuid();
    DB::connection('pgsql_migrate')->table('orders')->insert([
        'id' => $orderId, 'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'order_date' => '2026-07-26',
        'status' => OrderStatus::PENDING, 'total' => bcmul($rate, '2', 2),
        'created_by' => $owner->id, 'sync_seq' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('pgsql_migrate')->table('order_lines')->insert([
        'id' => (string) Str::uuid(), 'business_id' => $business->id, 'order_id' => $orderId,
        'product_pack_id' => $pack->id, 'qty' => 2, 'rate' => $rate,
        'line_total' => bcmul($rate, '2', 2), 'sync_seq' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$owner, $business, $orderId, $pack];
}

describe('access', function () {
    it('redirects a guest to login', function () {
        $this->get('/orders')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $this->actingAs(User::factory()->create())->get('/orders')->assertRedirect(route('app'));
    });
});

describe('accepting', function () {
    it('lists a pending order with its customer and total', function () {
        [$owner, $business] = pendingOrder();

        $this->actingAs($owner)->get('/orders?business=' . $business->id)
            ->assertOk()
            ->assertSee('Ram Traders')
            ->assertSee('₹170.00');
    });

    it('accepts an order as taken', function () {
        [$owner, $business, $orderId] = pendingOrder();

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
        ])->assertRedirect(route('orders', ['business' => $business->id]));

        $order = DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)->first();
        expect($order->status)->toBe(OrderStatus::ACCEPTED);
        expect($order->accepted_by)->toBe($owner->id);
        expect($order->accepted_at)->not->toBeNull();
    });

    it('accepts with adjusted quantities and prices, and retotals the order', function () {
        [$owner, $business, $orderId, $pack] = pendingOrder();
        $lineId = DB::connection('pgsql_migrate')->table('order_lines')
            ->where('order_id', $orderId)->value('id');

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
            'lines' => [$lineId => ['qty' => '3', 'rate' => '95.00']],
        ])->assertRedirect();

        $line = DB::connection('pgsql_migrate')->table('order_lines')->where('id', $lineId)->first();
        expect($line->qty)->toBe(3);
        expect((string) $line->rate)->toBe('95.00');
        expect((string) $line->line_total)->toBe('285.00');
        expect((string) DB::connection('pgsql_migrate')->table('orders')
            ->where('id', $orderId)->value('total'))->toBe('285.00');
    });

    it('refuses an adjusted price below the cost floor', function () {
        [$owner, $business, $orderId] = pendingOrder();
        $lineId = DB::connection('pgsql_migrate')->table('order_lines')
            ->where('order_id', $orderId)->value('id');

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
            'lines' => [$lineId => ['qty' => '2', 'rate' => '69.99']],
        ])->assertRedirect();

        // Still pending, still at the original rate: the edit was refused whole.
        $order = DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)->first();
        expect($order->status)->toBe(OrderStatus::PENDING);
        expect((string) DB::connection('pgsql_migrate')->table('order_lines')
            ->where('id', $lineId)->value('rate'))->toBe('85.00');
    });

    it('rejects an order with a reason', function () {
        [$owner, $business, $orderId] = pendingOrder();

        $this->actingAs($owner)->post('/orders/' . $orderId . '/reject', [
            'business' => $business->id, 'status_note' => 'No stock this week',
        ])->assertRedirect();

        $order = DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)->first();
        expect($order->status)->toBe(OrderStatus::REJECTED);
        expect($order->status_note)->toBe('No stock this week');
    });

    it('does not touch another tenant\'s order', function () {
        [$owner, $business] = pendingOrder();
        [, , $theirOrderId] = pendingOrder();

        $this->actingAs($owner)->post('/orders/' . $theirOrderId . '/accept', [
            'business' => $business->id,
        ])->assertNotFound();

        expect(DB::connection('pgsql_migrate')->table('orders')->where('id', $theirOrderId)->value('status'))
            ->toBe(OrderStatus::PENDING);
    });

    it('lets an admin accept, not only the owner', function () {
        [$owner, $business, $orderId] = pendingOrder();
        $admin = User::factory()->create();
        App\Models\Membership::on('pgsql_migrate')->create([
            'user_id' => $admin->id, 'business_id' => $business->id, 'role' => 'admin',
        ]);

        $this->actingAs($admin)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
        ])->assertRedirect();

        expect(DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)->value('status'))
            ->toBe(OrderStatus::ACCEPTED);
    });

    it('refuses to accept an order that is no longer pending', function () {
        [$owner, $business, $orderId] = pendingOrder();
        DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)
            ->update(['status' => OrderStatus::CANCELLED]);

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
        ])->assertRedirect();

        expect(DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)->value('status'))
            ->toBe(OrderStatus::CANCELLED);
    });
});
