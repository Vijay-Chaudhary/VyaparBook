<?php
// tests/Feature/Web/OrdersTest.php

use App\Models\Customer;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Orders\OrderStatus;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A pending order for the owner to act on. Returns [owner, business, orderId, pack]. */
function pendingOrder(string $rate = '85.00'): array
{
    [$owner, $business] = pwOwner();

    return asTenant($business->id, function () use ($owner, $business, $rate) {
        return pendingOrderRows($owner, $business, $rate);
    });
}

/** The rows themselves, written with $business bound as the tenant. */
function pendingOrderRows(User $owner, \App\Models\Business $business, string $rate): array
{
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

    $orderId = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $orderId, 'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'order_date' => '2026-07-26',
        'status' => OrderStatus::PENDING, 'total' => bcmul($rate, '2', 2),
        'created_by' => $owner->id, 'sync_seq' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('order_lines')->insert([
        'id' => (string) Str::uuid(), 'business_id' => $business->id, 'order_id' => $orderId,
        'product_pack_id' => $pack->id, 'qty' => 2, 'rate' => $rate,
        // Stamped at creation by OrderWriter, so a realistic pending line has
        // them and they equal the live values until acceptance edits those.
        'ordered_qty' => 2, 'ordered_rate' => $rate,
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
    it('links the customer on an order through to their khata', function () {
        [$owner, $business] = pendingOrder();
        // asTenant, not a bare where(): the scope refuses an unbound query
        // before it ever looks at the predicate.
        $customer = asTenant($business->id, fn () => Customer::firstOrFail());

        // Deciding whether to accept an order means knowing what this customer
        // already owes, which this screen could not show.
        $this->actingAs($owner)->get('/orders?business=' . $business->id)
            ->assertOk()
            ->assertSee(route('customers.show', [
                'customer' => $customer->id, 'business' => $business->id,
            ]), false);
    });

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

        $order = DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)->first();
        expect($order->status)->toBe(OrderStatus::ACCEPTED);
        expect($order->accepted_by)->toBe($owner->id);
        expect($order->accepted_at)->not->toBeNull();
    });

    it('accepts with adjusted quantities and prices, and retotals the order', function () {
        [$owner, $business, $orderId, $pack] = pendingOrder();
        $lineId = DB::table('order_lines')->where('business_id', $business->id)
            ->where('order_id', $orderId)->value('id');

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
            'lines' => [$lineId => ['qty' => '3', 'rate' => '95.00']],
        ])->assertRedirect();

        $line = DB::table('order_lines')->where('business_id', $business->id)->where('id', $lineId)->first();
        expect($line->qty)->toBe(3);
        expect((string) $line->rate)->toBe('95.00');
        expect((string) $line->line_total)->toBe('285.00');
        expect((string) DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)->value('total'))->toBe('285.00');
    });

    it('accepts an adjusted price below cost rather than refusing it', function () {
        [$owner, $business, $orderId] = pendingOrder();
        $lineId = DB::table('order_lines')->where('business_id', $business->id)
            ->where('order_id', $orderId)->value('id');

        // 69.99 is under the 70.00 cost pendingOrder() builds. This used to
        // refuse the whole acceptance; the shop sells under cost on purpose.
        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
            'lines' => [$lineId => ['qty' => '2', 'rate' => '69.99']],
        ])->assertRedirect();

        $order = DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)->first();
        expect($order->status)->toBe(OrderStatus::ACCEPTED);
        expect((string) DB::table('order_lines')->where('business_id', $business->id)->where('id', $lineId)->value('rate'))->toBe('69.99');
    });

    it('warns on the screen when a line sits under cost', function () {
        // Advice replaced the refusal, so the owner must be able to SEE it —
        // otherwise the rate is adjusted with no cost reference at all.
        [$owner, $business, $orderId] = pendingOrder('69.99');

        $this->actingAs($owner)->get('/orders?business=' . $business->id)
            ->assertOk()
            ->assertSee(__('orders.under_cost', ['cost' => '₹70.00']));
    });

    it('says nothing about cost on a line that is above it', function () {
        [$owner, $business] = pendingOrder('85.00');

        $this->actingAs($owner)->get('/orders?business=' . $business->id)
            ->assertOk()
            ->assertDontSee(__('orders.under_cost', ['cost' => '₹70.00']));
    });

    it('rejects an order with a reason', function () {
        [$owner, $business, $orderId] = pendingOrder();

        $this->actingAs($owner)->post('/orders/' . $orderId . '/reject', [
            'business' => $business->id, 'status_note' => 'No stock this week',
        ])->assertRedirect();

        $order = DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)->first();
        expect($order->status)->toBe(OrderStatus::REJECTED);
        expect($order->status_note)->toBe('No stock this week');
    });

    it('does not touch another tenant\'s order', function () {
        [$owner, $business] = pendingOrder();
        [, , $theirOrderId] = pendingOrder();

        $this->actingAs($owner)->post('/orders/' . $theirOrderId . '/accept', [
            'business' => $business->id,
        ])->assertNotFound();

        // Deliberately cross-tenant: the whole point is that the OTHER shop's
        // order is untouched, so it cannot be read under this tenant.
        expect(Tenancy::withoutTenant(
            fn () => DB::table('orders')->where('id', $theirOrderId)->value('status')
        ))->toBe(OrderStatus::PENDING);
    });

    it('lets an admin accept, not only the owner', function () {
        [$owner, $business, $orderId] = pendingOrder();
        $admin = User::factory()->create();
        App\Models\Membership::create([
            'user_id' => $admin->id, 'business_id' => $business->id, 'role' => 'admin',
        ]);

        $this->actingAs($admin)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
        ])->assertRedirect();

        expect(DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)->value('status'))
            ->toBe(OrderStatus::ACCEPTED);
    });

    it('refuses to accept an order that is no longer pending', function () {
        [$owner, $business, $orderId] = pendingOrder();
        DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)
            ->update(['status' => OrderStatus::CANCELLED]);

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
        ])->assertRedirect();

        expect(DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)->value('status'))
            ->toBe(OrderStatus::CANCELLED);
    });
});

describe('what acceptance changed', function () {
    it('keeps what the salesman ordered when the owner edits the line', function () {
        [$owner, $business, $orderId] = pendingOrder();
        $lineId = DB::table('order_lines')->where('business_id', $business->id)
            ->where('order_id', $orderId)->value('id');

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
            'lines' => [$lineId => ['qty' => '3', 'rate' => '95.00']],
        ])->assertRedirect();

        $line = DB::table('order_lines')->where('business_id', $business->id)->where('id', $lineId)->first();
        // Live values are the owner's; the originals are untouched, which is
        // the whole point — a shop promised 2 at ₹85 and given 3 at ₹95 now
        // leaves a trace.
        expect($line->qty)->toBe(3);
        expect($line->ordered_qty)->toBe(2);
        expect((string) $line->ordered_rate)->toBe('85.00');
    });

    it('leaves the originals equal when acceptance changes nothing', function () {
        [$owner, $business, $orderId] = pendingOrder();
        $lineId = DB::table('order_lines')->where('business_id', $business->id)
            ->where('order_id', $orderId)->value('id');

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
        ])->assertRedirect();

        $line = DB::table('order_lines')->where('business_id', $business->id)->where('id', $lineId)->first();
        expect($line->ordered_qty)->toBe($line->qty);
        expect((string) $line->ordered_rate)->toBe((string) $line->rate);
    });

    it('shows the owner what an edited order was ordered as', function () {
        [$owner, $business, $orderId] = pendingOrder();
        $lineId = DB::table('order_lines')->where('business_id', $business->id)
            ->where('order_id', $orderId)->value('id');

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
            'lines' => [$lineId => ['qty' => '3', 'rate' => '95.00']],
        ])->assertRedirect();

        $this->actingAs($owner)->get('/orders?business=' . $business->id)
            ->assertOk()
            ->assertSee(__('orders.adjusted'))
            ->assertSee('₹285.00')                              // approved total
            ->assertSee(__('orders.was', ['value' => '₹170.00'])) // as it was taken
            ->assertSee('2 × ₹85.00');                          // the line as taken
    });

    it('says nothing about an order accepted exactly as it was taken', function () {
        [$owner, $business, $orderId] = pendingOrder();

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
        ])->assertRedirect();

        $this->actingAs($owner)->get('/orders?business=' . $business->id)
            ->assertOk()
            ->assertDontSee(__('orders.adjusted'));
    });

    it('says nothing about an order taken before the trail existed', function () {
        // Null means unknown, not unchanged. Showing "changed at approval"
        // here would invent a renegotiation; showing a "was" the data cannot
        // support would be worse.
        [$owner, $business, $orderId] = pendingOrder();
        $lineId = DB::table('order_lines')->where('business_id', $business->id)
            ->where('order_id', $orderId)->value('id');
        DB::table('order_lines')->where('business_id', $business->id)->where('id', $lineId)
            ->update(['ordered_qty' => null, 'ordered_rate' => null]);

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
            'lines' => [$lineId => ['qty' => '3', 'rate' => '95.00']],
        ])->assertRedirect();

        $this->actingAs($owner)->get('/orders?business=' . $business->id)
            ->assertOk()
            ->assertDontSee(__('orders.adjusted'));
    });
});

it('shows what was in a decided order, not just its total', function () {
    // A decided order showing only a total cannot answer "what did we agree to
    // send them?" — the question the owner opens this list to settle.
    [$owner, $business, $orderId] = pendingOrder();
    DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)
        ->update(['status' => OrderStatus::DELIVERED]);

    $this->actingAs($owner)->get('/orders?business=' . $business->id)
        ->assertOk()
        ->assertSee(__('orders.recent'))
        ->assertSee('Sev')            // the product on the line
        ->assertSee('500g')           // its pack size
        ->assertSee('₹85.00');        // the rate that was agreed
});

describe('cancelling', function () {
    it('lets the owner cancel an order the shop will not fulfil', function () {
        // A salesman could already cancel from the phone; the owner could not,
        // and was left watching an order they had decided against.
        [$owner, $business, $orderId] = pendingOrder();
        DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)
            ->update(['status' => OrderStatus::ACCEPTED]);

        $this->actingAs($owner)->post('/orders/' . $orderId . '/cancel', [
            'business' => $business->id, 'status_note' => 'Shop closed down',
        ])->assertRedirect(route('orders', ['business' => $business->id]));

        $order = DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)->first();
        expect($order->status)->toBe(OrderStatus::CANCELLED);
        expect($order->status_note)->toBe('Shop closed down');
    });

    it('writes no sale — an order before delivery was never money', function () {
        [$owner, $business, $orderId] = pendingOrder();
        DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)
            ->update(['status' => OrderStatus::PACKED]);

        $this->actingAs($owner)->post('/orders/' . $orderId . '/cancel', [
            'business' => $business->id,
        ])->assertRedirect();

        // Nothing to reverse, so nothing is written: cancelling is a real
        // cancel here, unlike a sale, which can only ever be mirrored.
        expect(DB::table('sales')
            ->where('business_id', $business->id)->count())->toBe(0);
    });

    it('refuses to cancel a delivered order, which is already money', function () {
        [$owner, $business, $orderId] = pendingOrder();
        DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)
            ->update(['status' => OrderStatus::DELIVERED]);

        $this->actingAs($owner)->post('/orders/' . $orderId . '/cancel', [
            'business' => $business->id,
        ])->assertSessionHas('error', __('orders.cannot_cancel'));

        expect(DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)->value('status'))
            ->toBe(OrderStatus::DELIVERED);
    });

    it('offers cancel on the screen for an order still open', function () {
        [$owner, $business, $orderId] = pendingOrder();
        DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)
            ->update(['status' => OrderStatus::ACCEPTED]);

        $this->actingAs($owner)->get('/orders?business=' . $business->id)
            ->assertOk()
            ->assertSee(route('orders.cancel', ['order' => $orderId]), false);
    });

    it('does not offer cancel on a finished order', function () {
        [$owner, $business, $orderId] = pendingOrder();
        DB::table('orders')->where('business_id', $business->id)->where('id', $orderId)
            ->update(['status' => OrderStatus::DELIVERED]);

        $this->actingAs($owner)->get('/orders?business=' . $business->id)
            ->assertOk()
            ->assertDontSee(route('orders.cancel', ['order' => $orderId]), false);
    });

    it('does not cancel another tenant\'s order', function () {
        [$owner, $business] = pendingOrder();
        [, , $theirOrderId] = pendingOrder();

        $this->actingAs($owner)->post('/orders/' . $theirOrderId . '/cancel', [
            'business' => $business->id,
        ])->assertNotFound();

        // Deliberately cross-tenant: the whole point is that the OTHER shop's
        // order is untouched, so it cannot be read under this tenant.
        expect(Tenancy::withoutTenant(
            fn () => DB::table('orders')->where('id', $theirOrderId)->value('status')
        ))->toBe(OrderStatus::PENDING);
    });
});

describe('owner corrections', function () {
    /** Walk the seeded pending order to delivered, so a sale exists to correct. */
    function deliverSeededOrder(User $owner, \App\Models\Business $business, string $orderId): void
    {
        asTenant($business->id, function () use ($orderId) {
            $uuid = \App\Models\Order::findOrFail($orderId)->uuid;
            app()->bind('tenant.user_id', fn () => \App\Models\Order::findOrFail($orderId)->created_by);
            app()->bind('tenant.role', fn () => 'owner');

            $order = \App\Models\Order::findOrFail($orderId);
            $order->status = OrderStatus::ACCEPTED;
            $order->accepted_at = now();
            $order->save();

            $writer = app(\App\Services\OrderWriter::class);
            $writer->pack($uuid);
            $writer->deliver($uuid);
        });
    }

    it('corrects a delivered order and reverses the sale it had already written', function () {
        [$owner, $business, $orderId] = pendingOrder('100.00');
        deliverSeededOrder($owner, $business, $orderId);

        $lineId = asTenant($business->id, fn () => \App\Models\OrderLine::firstOrFail()->id);

        $this->actingAs($owner)->post("/orders/{$orderId}/revise", [
            'business' => $business->id,
            'lines' => [$lineId => ['qty' => 1, 'rate' => '100.00']],
        ])->assertRedirect(route('orders', ['business' => $business->id]));

        [$status, $total] = Tenancy::withoutTenant(fn () => [
            DB::table('orders')->where('id', $orderId)->value('status'),
            DB::table('orders')->where('id', $orderId)->value('total'),
        ]);

        // Still delivered: the goods went out, only the figures were wrong.
        expect($status)->toBe(OrderStatus::DELIVERED)
            ->and($total)->toBe('100.00');

        // 200 sale + (-200) reversal + 100 corrected — nothing deleted.
        expect(Tenancy::withoutTenant(
            fn () => DB::table('sales')->where('business_id', $business->id)->count()
        ))->toBe(3);
    });

    it('voids a delivered order and reverses its sale', function () {
        [$owner, $business, $orderId] = pendingOrder('100.00');
        deliverSeededOrder($owner, $business, $orderId);

        $this->actingAs($owner)->post("/orders/{$orderId}/void", [
            'business' => $business->id,
            'status_note' => 'Customer returned it',
        ])->assertRedirect(route('orders', ['business' => $business->id]));

        [$status, $note, $saleId] = Tenancy::withoutTenant(fn () => [
            DB::table('orders')->where('id', $orderId)->value('status'),
            DB::table('orders')->where('id', $orderId)->value('status_note'),
            DB::table('orders')->where('id', $orderId)->value('sale_id'),
        ]);

        expect($status)->toBe(OrderStatus::CANCELLED)
            ->and($note)->toBe('Customer returned it')
            ->and($saleId)->toBeNull();
    });

    it('opens the correction form for the order the khata linked to', function () {
        // The app's "Correct this order" link carries ?order={id}. Opened
        // server-side because :target cannot force a <details> open and this
        // screen carries no JS of its own.
        [$owner, $business, $orderId] = pendingOrder('100.00');
        deliverSeededOrder($owner, $business, $orderId);

        $html = $this->actingAs($owner)
            ->get("/orders?business={$business->id}&order={$orderId}")
            ->assertOk()
            ->getContent();

        expect($html)->toContain('id="order-' . $orderId . '" open');
    });

    it('leaves every correction form shut when no order was asked for', function () {
        [$owner, $business, $orderId] = pendingOrder('100.00');
        deliverSeededOrder($owner, $business, $orderId);

        $html = $this->actingAs($owner)
            ->get("/orders?business={$business->id}")
            ->assertOk()
            ->getContent();

        expect($html)->toContain('id="order-' . $orderId . '"')
            ->not->toContain('id="order-' . $orderId . '" open');
    });

    it('keeps a salesman away from both corrections', function () {
        [$owner, $business, $orderId] = pendingOrder('100.00');

        $salesman = User::factory()->create();
        asTenant($business->id, fn () => \App\Models\Membership::create([
            'user_id' => $salesman->id, 'business_id' => $business->id, 'role' => 'salesman',
        ]));

        // ownedBusinessId() admits only owner/admin, so a salesman with a
        // perfectly good session is bounced before touching the order.
        $this->actingAs($salesman)->post("/orders/{$orderId}/void", [
            'business' => $business->id,
        ])->assertRedirect(route('app'));

        $this->actingAs($salesman)->post("/orders/{$orderId}/revise", [
            'business' => $business->id,
            'lines' => ['whatever' => ['qty' => 1, 'rate' => '1.00']],
        ])->assertRedirect(route('app'));

        expect(Tenancy::withoutTenant(
            fn () => DB::table('orders')->where('id', $orderId)->value('status')
        ))->toBe(OrderStatus::PENDING);
    });

    it('does not leak another tenant\'s order to the correction routes', function () {
        [$owner, $business] = pendingOrder();
        [$them, $theirBusiness, $theirOrderId] = pendingOrder();

        $this->actingAs($owner)->post("/orders/{$theirOrderId}/void", [
            'business' => $business->id,
        ])->assertNotFound();

        expect(Tenancy::withoutTenant(
            fn () => DB::table('orders')->where('id', $theirOrderId)->value('status')
        ))->toBe(OrderStatus::PENDING);
    });
});
