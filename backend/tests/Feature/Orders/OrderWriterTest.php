<?php
// tests/Feature/Orders/OrderWriterTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Order;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Orders\OrderStatus;
use App\Services\KhataService;
use App\Services\OrderWriter;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A shop, a salesman, a customer and one pack at 90.00 with a 70.00 cost floor. */
function orderSetup(string $role = 'salesman'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);

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

    return [$business, $user, $customer, $pack];
}

/** Run inside the tenant pin, as the sync push does in production. */
function inOrderTenant(Business $b, User $u, callable $fn, string $role = 'salesman'): mixed
{
    return DB::transaction(function () use ($b, $u, $fn, $role) {
        TenantContext::switchTo($b->id);
        app()->bind('tenant.id', fn () => $b->id);
        app()->bind('tenant.user_id', fn () => $u->id);
        app()->bind('tenant.role', fn () => $role);

        return $fn();
    });
}

function orderPayload(Customer $c, ProductPack $pack, array $over = []): array
{
    return array_merge([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id,
        'order_date' => '2026-07-26',
        'lines' => [['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '85.00']],
    ], $over);
}

it('creates a pending order with a total from its lines', function () {
    [$b, $u, $c, $pack] = orderSetup();

    [$order, $created] = inOrderTenant($b, $u, fn () => app(OrderWriter::class)
        ->createOrder(orderPayload($c, $pack)));

    expect($created)->toBeTrue();
    expect($order->status)->toBe(OrderStatus::PENDING);
    expect((string) $order->total)->toBe('170.00');   // 2 x 85.00
    expect($order->created_by)->toBe($u->id);
});

it('does not move the khata — an order is not money owed', function () {
    [$b, $u, $c, $pack] = orderSetup();

    inOrderTenant($b, $u, fn () => app(OrderWriter::class)->createOrder(orderPayload($c, $pack)));

    $outstanding = inOrderTenant($b, $u, fn () => app(KhataService::class)
        ->outstandingFor(Customer::findOrFail($c->id)));

    // The entire point of the design: nothing is owed until goods arrive.
    expect($outstanding)->toBe('0.00');
    expect(DB::connection('pgsql_migrate')->table('sales')->where('business_id', $b->id)->count())->toBe(0);
});

it('is idempotent by uuid, so a replayed push creates one order', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $payload = orderPayload($c, $pack);

    inOrderTenant($b, $u, fn () => app(OrderWriter::class)->createOrder($payload));
    [$order, $created] = inOrderTenant($b, $u, fn () => app(OrderWriter::class)->createOrder($payload));

    expect($created)->toBeFalse();
    expect(DB::connection('pgsql_migrate')->table('orders')->where('business_id', $b->id)->count())->toBe(1);
    expect($order->id)->not->toBeNull();
});

it('refuses a line below the pack cost floor, exactly as a sale would', function () {
    [$b, $u, $c, $pack] = orderSetup();

    $call = fn () => inOrderTenant($b, $u, fn () => app(OrderWriter::class)->createOrder(
        orderPayload($c, $pack, ['lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '69.99']]])
    ));

    expect($call)->toThrow(Illuminate\Validation\ValidationException::class);
    expect(DB::connection('pgsql_migrate')->table('orders')->where('business_id', $b->id)->count())->toBe(0);
});

it('refuses another tenant\'s customer', function () {
    [$b, $u, , $pack] = orderSetup();
    [$other] = orderSetup();
    $theirCustomer = Customer::on('pgsql_migrate')->create([
        'business_id' => $other->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Not Yours', 'opening_balance' => '0.00',
    ]);

    $call = fn () => inOrderTenant($b, $u, fn () => app(OrderWriter::class)->createOrder([
        'uuid' => (string) Str::uuid(), 'customer_id' => $theirCustomer->id,
        'order_date' => '2026-07-26',
        'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '85.00']],
    ]));

    // Invisible under RLS, so it 404s rather than leaking that it exists.
    expect($call)->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
