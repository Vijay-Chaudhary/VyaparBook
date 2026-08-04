<?php
// tests/Feature/Orders/OrderRevisionTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Order;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\Sale;
use App\Models\User;
use App\Orders\OrderStatus;
use App\Services\KhataService;
use App\Services\OrderWriter;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
| The owner's correction path: revising and voiding orders the state machine
| treats as finished. Held apart from OrderWriterTest because everything here
| deliberately bypasses OrderStatus, and that bypass is the thing under test.
|
| Helpers are named revision* rather than reusing OrderWriterTest's globals —
| Pest loads every test file into one process, so two files cannot declare the
| same function.
*/

/** A shop, a salesman, a customer and one pack at 90.00 (cost 70.00). */
function revisionSetup(): array
{
    $business = Business::factory()->create();
    $salesman = User::factory()->create();
    $owner = User::factory()->create();

    Membership::create(['user_id' => $salesman->id, 'business_id' => $business->id, 'role' => 'salesman']);
    Membership::create(['user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner']);

    $customer = Customer::create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders', 'opening_balance' => '0.00',
    ]);

    $product = Product::create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $size = PackSize::create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $pack = ProductPack::create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '90.00',
        'default_cost_price' => '70.00',
    ]);

    return [$business, $salesman, $owner, $customer, $pack];
}

function inRevisionTenant(Business $b, User $u, callable $fn, string $role = 'salesman'): mixed
{
    return DB::transaction(function () use ($b, $u, $fn, $role) {
        TenantContext::switchTo($b->id);
        app()->bind('tenant.id', fn () => $b->id);
        app()->bind('tenant.user_id', fn () => $u->id);
        app()->bind('tenant.role', fn () => $role);

        return $fn();
    });
}

/**
 * Read an order from outside the tenant pin.
 *
 * withoutGlobalScopes() drops the BelongsToTenant predicate, so business_id is
 * written by hand — the query tripwire fails the test otherwise, which is the
 * point of it.
 */
function revisionOrder(Business $b, string $uuid): Order
{
    return Order::withoutGlobalScopes()
        ->where('business_id', $b->id)
        ->with('lines')
        ->where('uuid', $uuid)
        ->firstOrFail();
}

function revisionLineId(Business $b, string $uuid): string
{
    return revisionOrder($b, $uuid)->lines->first()->id;
}

function outstanding(Business $b, User $u, Customer $c): string
{
    return inRevisionTenant($b, $u, fn () => app(KhataService::class)
        ->outstandingFor(Customer::findOrFail($c->id)));
}

/** Take a salesman's pending order through accept → pack → deliver. */
function walkToDelivered(Business $b, User $salesman, User $owner, string $uuid): void
{
    inRevisionTenant($b, $owner, function () use ($uuid) {
        $order = Order::where('uuid', $uuid)->firstOrFail();
        $order->status = OrderStatus::ACCEPTED;
        $order->accepted_by = 1;
        $order->accepted_at = now();
        $order->save();
    }, 'owner');

    inRevisionTenant($b, $salesman, function () use ($uuid) {
        $writer = app(OrderWriter::class);
        $writer->pack($uuid);
        $writer->deliver($uuid);
    });
}

function placeOrder(Business $b, User $salesman, Customer $c, ProductPack $pack, int $qty = 2, string $rate = '100.00'): string
{
    $uuid = (string) Str::uuid();

    inRevisionTenant($b, $salesman, fn () => app(OrderWriter::class)->createOrder([
        'uuid' => $uuid,
        'customer_id' => $c->id,
        'order_date' => '2026-08-01',
        'lines' => [['product_pack_id' => $pack->id, 'qty' => $qty, 'rate' => $rate]],
    ]));

    return $uuid;
}

describe('self-approval', function () {
    it('accepts an order the owner placed themselves, with nobody to wait for', function () {
        [$b, , $owner, $c, $pack] = revisionSetup();

        [$order] = inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)->createOrder([
            'uuid' => (string) Str::uuid(), 'customer_id' => $c->id, 'order_date' => '2026-08-01',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '100.00']],
        ]), 'owner');

        expect($order->status)->toBe(OrderStatus::ACCEPTED)
            ->and($order->accepted_by)->toBe($owner->id)
            ->and($order->accepted_at)->not->toBeNull();
    });

    it('accepts an admin order too, since an admin may already approve', function () {
        [$b, , $owner, $c, $pack] = revisionSetup();

        [$order] = inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)->createOrder([
            'uuid' => (string) Str::uuid(), 'customer_id' => $c->id, 'order_date' => '2026-08-01',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00']],
        ]), 'admin');

        expect($order->status)->toBe(OrderStatus::ACCEPTED);
    });

    it('still queues a salesman order for approval', function () {
        [$b, $salesman, , $c, $pack] = revisionSetup();

        [$order] = inRevisionTenant($b, $salesman, fn () => app(OrderWriter::class)->createOrder([
            'uuid' => (string) Str::uuid(), 'customer_id' => $c->id, 'order_date' => '2026-08-01',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '100.00']],
        ]));

        expect($order->status)->toBe(OrderStatus::PENDING)
            ->and($order->accepted_by)->toBeNull()
            ->and($order->accepted_at)->toBeNull();
    });

    it('leaves an owner order showing no adjustment, because nothing was adjusted', function () {
        [$b, , $owner, $c, $pack] = revisionSetup();

        [$order] = inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)->createOrder([
            'uuid' => (string) Str::uuid(), 'customer_id' => $c->id, 'order_date' => '2026-08-01',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 3, 'rate' => '95.00']],
        ]), 'owner');

        $line = $order->lines->first();

        expect($line->ordered_qty)->toBe($line->qty)
            ->and((string) $line->ordered_rate)->toBe((string) $line->rate);
    });
});

describe('revising a delivered order', function () {
    it('voids the sale and issues a corrected one, leaving the khata on the new figure', function () {
        [$b, $salesman, $owner, $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack);
        walkToDelivered($b, $salesman, $owner, $uuid);

        expect(outstanding($b, $owner, $c))->toBe('200.00');

        $order = revisionOrder($b, $uuid);
        $lineId = $order->lines->first()->id;

        inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)
            ->reviseOrder($uuid, [$lineId => ['qty' => 1, 'rate' => '100.00']]), 'owner');

        // 200 sale, -200 reversal, 100 corrected.
        expect(outstanding($b, $owner, $c))->toBe('100.00');

        $sales = Sale::withoutGlobalScopes()->where('business_id', $b->id)->get();
        expect($sales)->toHaveCount(3)
            ->and($sales->whereNotNull('reverses_id'))->toHaveCount(1);
    });

    it('keeps the order delivered — the goods did go out, only the figures were wrong', function () {
        [$b, $salesman, $owner, $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack);
        walkToDelivered($b, $salesman, $owner, $uuid);

        $order = revisionOrder($b, $uuid);
        $lineId = $order->lines->first()->id;

        [$revised] = inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)
            ->reviseOrder($uuid, [$lineId => ['qty' => 1, 'rate' => '100.00']]), 'owner');

        expect($revised->status)->toBe(OrderStatus::DELIVERED)
            ->and($revised->total)->toBe('100.00')
            ->and($revised->revision)->toBe(1)
            ->and($revised->sale_id)->not->toBeNull();
    });

    it('preserves what the salesman originally asked for', function () {
        [$b, $salesman, $owner, $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack, 2, '100.00');
        walkToDelivered($b, $salesman, $owner, $uuid);

        $order = revisionOrder($b, $uuid);
        $lineId = $order->lines->first()->id;

        inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)
            ->reviseOrder($uuid, [$lineId => ['qty' => 5, 'rate' => '80.00']]), 'owner');

        $line = revisionOrder($b, $uuid)->lines->first();

        expect($line->qty)->toBe(5)
            ->and((string) $line->rate)->toBe('80.00')
            // untouched: the audit trail of what was ordered
            ->and($line->ordered_qty)->toBe(2)
            ->and((string) $line->ordered_rate)->toBe('100.00');
    });

    it('chains across repeated corrections rather than refusing the second', function () {
        [$b, $salesman, $owner, $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack);
        walkToDelivered($b, $salesman, $owner, $uuid);

        $lineId = revisionLineId($b, $uuid);

        inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)
            ->reviseOrder($uuid, [$lineId => ['qty' => 3, 'rate' => '100.00']]), 'owner');
        [$second] = inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)
            ->reviseOrder($uuid, [$lineId => ['qty' => 4, 'rate' => '100.00']]), 'owner');

        expect(outstanding($b, $owner, $c))->toBe('400.00')
            ->and($second->revision)->toBe(2);
    });

    it('does not touch the khata when the order was never delivered', function () {
        [$b, $salesman, $owner, $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack);

        $lineId = revisionLineId($b, $uuid);

        [$revised] = inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)
            ->reviseOrder($uuid, [$lineId => ['qty' => 7, 'rate' => '100.00']]), 'owner');

        expect($revised->status)->toBe(OrderStatus::PENDING)
            ->and($revised->total)->toBe('700.00')
            ->and($revised->revision)->toBe(0)
            ->and(Sale::withoutGlobalScopes()->where('business_id', $b->id)->count())->toBe(0)
            ->and(outstanding($b, $owner, $c))->toBe('0.00');
    });

    it('refuses to revise a cancelled order rather than resurrecting it', function () {
        [$b, $salesman, $owner, $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack);

        $lineId = revisionLineId($b, $uuid);

        inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)->voidOrder($uuid), 'owner');

        inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)
            ->reviseOrder($uuid, [$lineId => ['qty' => 1, 'rate' => '100.00']]), 'owner');
    })->throws(ValidationException::class);
});

describe('voiding an order', function () {
    it('reverses a delivered order back to nothing owed', function () {
        [$b, $salesman, $owner, $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack);
        walkToDelivered($b, $salesman, $owner, $uuid);

        expect(outstanding($b, $owner, $c))->toBe('200.00');

        [$order, $applied] = inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)
            ->voidOrder($uuid, 'Returned by the customer'), 'owner');

        expect($applied)->toBeTrue()
            ->and($order->status)->toBe(OrderStatus::CANCELLED)
            ->and($order->status_note)->toBe('Returned by the customer')
            ->and($order->sale_id)->toBeNull()
            ->and(outstanding($b, $owner, $c))->toBe('0.00');

        // Append-only: nothing was deleted, the pair just nets to zero.
        expect(Sale::withoutGlobalScopes()->where('business_id', $b->id)->count())->toBe(2);
    });

    it('treats a repeated void as a duplicate and appends no second reversal', function () {
        [$b, $salesman, $owner, $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack);
        walkToDelivered($b, $salesman, $owner, $uuid);

        inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)->voidOrder($uuid), 'owner');
        [, $applied] = inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)
            ->voidOrder($uuid), 'owner');

        expect($applied)->toBeFalse()
            ->and(Sale::withoutGlobalScopes()->where('business_id', $b->id)->count())->toBe(2)
            ->and(outstanding($b, $owner, $c))->toBe('0.00');
    });

    it('cancels an undelivered order without inventing a sale to reverse', function () {
        [$b, $salesman, $owner, $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack);

        [$order] = inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)
            ->voidOrder($uuid), 'owner');

        expect($order->status)->toBe(OrderStatus::CANCELLED)
            ->and(Sale::withoutGlobalScopes()->where('business_id', $b->id)->count())->toBe(0);
    });
});

describe('the state machine is bypassed, not weakened', function () {
    it('still refuses a phone pushing a stale cancel at a delivered order', function () {
        [$b, $salesman, $owner, $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack);
        walkToDelivered($b, $salesman, $owner, $uuid);

        // The sync path is unchanged: only the owner's explicit correction may
        // touch a terminal order, and it does not go through cancel().
        inRevisionTenant($b, $salesman, fn () => app(OrderWriter::class)->cancel($uuid));
    })->throws(ValidationException::class);

    it('still refuses to pack an order the owner has not accepted', function () {
        [$b, $salesman, , $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack);

        inRevisionTenant($b, $salesman, fn () => app(OrderWriter::class)->pack($uuid));
    })->throws(ValidationException::class);
});

describe('stock does not double-count a correction', function () {
    it('nets finished goods to the corrected quantity, not the sum of both sales', function () {
        [$b, $salesman, $owner, $c, $pack] = revisionSetup();
        $uuid = placeOrder($b, $salesman, $c, $pack);          // 2 packs × 0.500 kg
        walkToDelivered($b, $salesman, $owner, $uuid);

        $onHand = fn () => inRevisionTenant($b, $owner, fn () => collect(
            app(\App\Services\FinishedGoodsService::class)->onHand($b->id)
        )->first()?->onHandKg, 'owner');

        // Nothing produced, so on-hand is simply negative sold kg.
        expect($onHand())->toBe('-1.000');

        inRevisionTenant($b, $owner, fn () => app(OrderWriter::class)
            ->reviseOrder($uuid, [revisionLineId($b, $uuid) => ['qty' => 1, 'rate' => '100.00']]), 'owner');

        // 2 sold, 2 returned by the void, 1 sold again — Σ qty self-nets, so
        // the correction does not count the goods twice.
        expect($onHand())->toBe('-0.500');
    });
});
