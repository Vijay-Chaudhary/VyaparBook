<?php
// tests/Feature/Web/CustomersTest.php
//
// pwOwner comes from tests/Pest.php.

use App\Ledger\LedgerReverser;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PackSize;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use App\Services\KhataService;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A customer belonging to $b. */
function cusCustomer(Business $b, string $name = 'Ramesh Kumar', string $opening = '0.00'): Customer
{
    return asTenant($b->id, fn () => Customer::create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'village' => 'Rampur', 'phone' => '9876543210',
        'opening_balance' => $opening,
    ]));
}

function cusSale(Business $b, User $u, Customer $c, string $total, string $date): Sale
{
    return asTenant($b->id, function () use ($b, $u, $c, $total, $date) {
    $s = new Sale([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'sale_date' => $date,
    ]);
    $s->total = $total;          // not fillable — SaleWriter stamps it in prod
    $s->created_by = $u->id;
    $s->save();

    return $s;
    });
}

function cusPayment(Business $b, User $u, Customer $c, string $amount, string $date): Payment
{
    return asTenant($b->id, function () use ($b, $u, $c, $amount, $date) {
    $p = new Payment([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'payment_date' => $date, 'amount' => $amount, 'mode' => 'cash',
    ]);
    $p->created_by = $u->id;
    $p->save();

    return $p;
    });
}

/**
 * One sale line, with the two snapshot columns set: an edit must recompute
 * line_total and leave list_rate and cost_at_sale exactly as the day it sold.
 */
function cusSaleLine(Business $b, Sale $s, int $qty, string $rate): SaleLine
{
    return asTenant($b->id, function () use ($b, $s, $qty, $rate) {
        $pack = ProductPack::factory()->create([
            'business_id' => $b->id,
            'product_id' => Product::factory()->create(['business_id' => $b->id])->id,
            'pack_size_id' => PackSize::factory()->create(['business_id' => $b->id])->id,
        ]);

        $line = SaleLine::factory()->make([
            'business_id' => $b->id, 'sale_id' => $s->id,
            'product_pack_id' => $pack->id, 'qty' => $qty, 'rate' => $rate,
        ]);
        $line->list_rate = $rate;
        $line->cost_at_sale = '30.00';
        $line->save();

        return $line;
    });
}

/** A filed tax invoice against $sale — the thing that freezes it. */
function cusInvoice(Business $b, User $u, Customer $c, Sale $sale): Invoice
{
    return asTenant($b->id, function () use ($b, $u, $c, $sale) {
        $invoice = new Invoice([
            'business_id' => $b->id, 'sale_id' => $sale->id,
            'number' => '2026-27/0001', 'financial_year' => '2026-27', 'seq' => 1,
            'issued_on' => '2026-07-20', 'buyer_name' => $c->name,
            'seller_gstin' => '09ABCDE1234F1Z5',
            'taxable_total' => (string) $sale->total, 'cgst_total' => '0.00',
            'sgst_total' => '0.00', 'grand_total' => (string) $sale->total,
        ]);
        $invoice->created_by = $u->id;   // not fillable — stamped, never posted
        $invoice->save();

        return $invoice;
    });
}

/** A delivered order pointing at $sale, which is what makes the sale the order's. */
function cusOrder(Business $b, User $u, Customer $c, Sale $sale): Order
{
    return asTenant($b->id, function () use ($b, $u, $c, $sale) {
        $order = new Order([
            'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
            'customer_id' => $c->id, 'order_date' => '2026-07-19',
        ]);
        // None of these are fillable: OrderWriter stamps them.
        $order->created_by = $u->id;
        $order->total = (string) $sale->total;
        $order->status = 'delivered';
        $order->sale_id = $sale->id;
        $order->save();

        return $order;
    });
}

/** Outstanding as the app computes it, read back tenant-pinned. */
function cusOutstanding(Customer $c): string
{
    return asTenant($c->business_id, fn () => (new KhataService())
        ->outstandingFor(Customer::find($c->id)));
}

/** How many entries the statement actually shows — a deleted row is not one. */
function cusLedgerCount(Customer $c): int
{
    return asTenant($c->business_id, fn () => (new KhataService())
        ->ledgerFor(Customer::find($c->id))->count());
}

describe('access', function () {
    it('redirects a guest to login', function () {
        $c = cusCustomer(Business::factory()->create());

        $this->get('/customers/' . $c->id)->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $c = cusCustomer(Business::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get('/customers/' . $c->id)->assertRedirect(route('app'));
    });

    it('refuses an owner asking for a business they do not own', function () {
        [$owner] = pwOwner();
        [, $theirs] = pwOwner();
        $c = cusCustomer($theirs);

        $this->actingAs($owner)->get('/customers/' . $c->id . '?business=' . $theirs->id)
            ->assertRedirect(route('app'));
    });
});

describe('render', function () {
    it('shows a khata whose running balance reconciles with outstanding', function () {
        [$owner, $business] = pwOwner();
        $c = cusCustomer($business, 'Ramesh Kumar', '500.00');

        // Opening 500 + sale 1,000 = 1,500; pay 600 → owes 900.
        cusSale($business, $owner, $c, '1000.00', '2026-07-04');
        cusPayment($business, $owner, $c, '600.00', '2026-07-06');

        $this->actingAs($owner)->get('/customers/' . $c->id . '?business=' . $business->id)
            ->assertOk()
            ->assertSee('Ramesh Kumar')
            ->assertSee('Rampur')
            ->assertSee('₹1,000.00')    // the sale, debited
            ->assertSee('−₹600.00')     // the payment, credited
            ->assertSee('₹900.00');     // running balance = outstanding
    });

    it('shows a customer with no entries rather than an empty page', function () {
        [$owner, $business] = pwOwner();
        $c = cusCustomer($business, 'Sunita Devi');

        $this->actingAs($owner)->get('/customers/' . $c->id . '?business=' . $business->id)
            ->assertOk()
            ->assertSee('Sunita Devi')
            ->assertSee(__('customers.no_entries'));
    });

    it('does not leak another tenant\'s customer', function () {
        [$owner, $business] = pwOwner();
        [, $other] = pwOwner();
        $foreign = cusCustomer($other, 'Other Shop Customer');

        $this->actingAs($owner)->get('/customers/' . $foreign->id . '?business=' . $business->id)
            ->assertRedirect(route('customers', ['business' => $business->id]));
    });
});

describe('index', function () {
    it('lists customers with what each owes, biggest first', function () {
        [$owner, $business] = pwOwner();
        $small = cusCustomer($business, 'Small Debtor');
        $big = cusCustomer($business, 'Big Debtor');
        cusSale($business, $owner, $small, '300.00', '2026-07-04');
        cusSale($business, $owner, $big, '4000.00', '2026-07-04');

        $body = $this->actingAs($owner)->get('/customers?business=' . $business->id)
            ->assertOk()
            ->assertSee('Big Debtor')
            ->assertSee('Small Debtor')
            ->assertSee('₹4,000.00')
            ->getContent();

        expect(strpos($body, 'Big Debtor'))->toBeLessThan(strpos($body, 'Small Debtor'));
    });

    it('does not list another tenant\'s customers', function () {
        [$owner, $business] = pwOwner();
        [, $other] = pwOwner();
        cusCustomer($other, 'Other Shop Customer');

        $this->actingAs($owner)->get('/customers?business=' . $business->id)
            ->assertOk()
            ->assertDontSee('Other Shop Customer');
    });

    it('redirects a guest to login', function () {
        $this->get('/customers')->assertRedirect(route('login'));
    });
});

describe('create', function () {
    it('adds a customer and shows them in the list', function () {
        [$owner, $business] = pwOwner();

        $this->actingAs($owner)->post('/customers', [
            'business' => $business->id,
            'name' => 'Nayi Dukan',
            'village' => 'Hata',
            'phone' => '9998887776',
            'opening_balance' => '250.50',
        ])->assertRedirect(route('customers', ['business' => $business->id]));

        $row = Customer::where('business_id', $business->id)
            ->where('name', 'Nayi Dukan')->firstOrFail();

        expect($row->village)->toBe('Hata')
            ->and($row->phone)->toBe('9998887776')
            ->and((string) $row->opening_balance)->toBe('250.50')
            ->and($row->uuid)->not->toBeNull();   // minted so the row can sync
    });

    it('requires a name', function () {
        [$owner, $business] = pwOwner();

        $this->actingAs($owner)
            ->post('/customers', ['business' => $business->id, 'name' => ''])
            ->assertSessionHasErrors('name');

        // The rejected request aborted before the tenant middleware bound a
        // tenant, so name the shop being checked rather than leaving the
        // fail-closed scope with nothing to scope to.
        expect(asTenant($business->id, fn () => Customer::count()))->toBe(0);
    });

    it('is idempotent on a replayed uuid, so a double submit adds one row', function () {
        [$owner, $business] = pwOwner();
        $uuid = (string) Str::uuid();

        foreach ([1, 2] as $_) {
            $this->actingAs($owner)->post('/customers', [
                'business' => $business->id, 'uuid' => $uuid, 'name' => 'Double Tap',
            ]);
        }

        expect(Customer::where('business_id', $business->id)
            ->where('name', 'Double Tap')->count())->toBe(1);
    });

    it('lets two customers share a name in different villages', function () {
        // Core to the domain: the seeded book has two Santosh Singhs.
        [$owner, $business] = pwOwner();

        foreach (['Aziz', 'Harpur'] as $village) {
            $this->actingAs($owner)->post('/customers', [
                'business' => $business->id, 'name' => 'Santosh Singh', 'village' => $village,
            ])->assertRedirect(route('customers', ['business' => $business->id]));
        }

        expect(Customer::where('business_id', $business->id)
            ->where('name', 'Santosh Singh')->count())->toBe(2);
    });
});

describe('update', function () {
    it('edits a customer, so a missing phone can be filled in later', function () {
        // A customer with no phone is blocked from reminders as `no_phone`;
        // this is how the owner unblocks them.
        [$owner, $business] = pwOwner();
        $c = cusCustomer($business, 'No Phone Yet');
        $c->phone = null;
        $c->save();

        $this->actingAs($owner)->patch('/customers/' . $c->id, [
            'business' => $business->id,
            'name' => 'Phone Added',
            'village' => 'Mathauli',
            'phone' => '9123456780',
        ])->assertRedirect(route('customers.show', ['customer' => $c->id, 'business' => $business->id]));

        $fresh = Customer::find($c->id);
        expect($fresh->name)->toBe('Phone Added')
            ->and($fresh->village)->toBe('Mathauli')
            ->and($fresh->phone)->toBe('9123456780');
    });

    it('leaves the khata untouched when the name changes', function () {
        // Editing who someone is must never restate what they owe.
        [$owner, $business] = pwOwner();
        $c = cusCustomer($business, 'Before', '100.00');
        cusSale($business, $owner, $c, '900.00', '2026-07-04');

        $this->actingAs($owner)->patch('/customers/' . $c->id, [
            'business' => $business->id, 'name' => 'After',
        ]);

        $fresh = Customer::find($c->id);
        expect(app(App\Services\KhataService::class)->outstandingFor($fresh))->toBe('1000.00');
    });

    it('refuses to edit another tenant\'s customer', function () {
        [$owner, $business] = pwOwner();
        [, $other] = pwOwner();
        $foreign = cusCustomer($other, 'Not Yours');

        $this->actingAs($owner)->patch('/customers/' . $foreign->id, [
            'business' => $business->id, 'name' => 'Hijacked',
        ]);

        // Deliberately cross-tenant: the assertion is that the OTHER shop's row
        // is unchanged, which cannot be read from inside this tenant.
        // withoutTenant rather than withoutGlobalScopes, so the intent is
        // greppable and the query tripwire knows it was meant.
        expect(Tenancy::withoutTenant(fn () => Customer::find($foreign->id)->name))
            ->toBe('Not Yours');
    });
});

describe('archive', function () {
    it('archives a customer instead of deleting them, keeping the ledger whole', function () {
        [$owner, $business] = pwOwner();
        $c = cusCustomer($business, 'Gone Quiet');
        cusSale($business, $owner, $c, '750.00', '2026-07-04');

        $this->actingAs($owner)->delete('/customers/' . $c->id, ['business' => $business->id])
            ->assertRedirect(route('customers', ['business' => $business->id]));

        $fresh = Customer::find($c->id);
        expect($fresh)->not->toBeNull()                 // the row survives
            ->and($fresh->archived_at)->not->toBeNull();

        // and the sale it carried is still there to be counted
        expect(Sale::where('customer_id', $c->id)->count())->toBe(1);
    });

    it('drops an archived customer out of the active list', function () {
        [$owner, $business] = pwOwner();
        $c = cusCustomer($business, 'Archived Person');
        $this->actingAs($owner)->delete('/customers/' . $c->id, ['business' => $business->id]);

        $this->actingAs($owner)->get('/customers?business=' . $business->id)
            ->assertOk()
            ->assertSee(__('customers.archived_heading'));
    });

    it('restores an archived customer', function () {
        [$owner, $business] = pwOwner();
        $c = cusCustomer($business, 'Back Again');
        $this->actingAs($owner)->delete('/customers/' . $c->id, ['business' => $business->id]);

        $this->actingAs($owner)->post('/customers/' . $c->id . '/restore', ['business' => $business->id])
            ->assertRedirect(route('customers', ['business' => $business->id]));

        expect(Customer::find($c->id)->archived_at)->toBeNull();
    });

    it('refuses to archive another tenant\'s customer', function () {
        [$owner, $business] = pwOwner();
        [, $other] = pwOwner();
        $foreign = cusCustomer($other, 'Not Yours Either');

        $this->actingAs($owner)->delete('/customers/' . $foreign->id, ['business' => $business->id]);

        // Cross-tenant on purpose, as above: the other shop's customer must be
        // untouched.
        expect(Tenancy::withoutTenant(fn () => Customer::find($foreign->id)->archived_at))
            ->toBeNull();
    });
});

describe('corrections', function () {
    it('edits a payment in place, so the khata says what really happened', function () {
        // This is the change the owner asked for. The old behaviour wrote
        // "paid 200, reversed 200, paid 150" for one mistyped payment, and a
        // khata that reports three events for one is not the shop's khata.
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business, 'Ramesh Kumar', '500.00');
        $payment = cusPayment($business, $owner, $customer, '200.00', '2026-07-20');

        $this->actingAs($owner)
            ->patch("/customers/{$customer->id}/payments/{$payment->id}", [
                'business' => $business->id, 'amount' => '150.00',
                'payment_date' => '2026-07-21', 'mode' => 'upi',
            ])
            ->assertRedirect(route('customers.show', ['customer' => $customer->id, 'business' => $business->id]));

        // One row, still — carrying the corrected figures, with no cancelling
        // entry beside it.
        $rows = Tenancy::withoutTenant(fn () => DB::table('payments')
            ->where('business_id', $business->id)->get());

        expect($rows)->toHaveCount(1)
            ->and((string) $rows[0]->amount)->toBe('150.00')
            ->and($rows[0]->mode)->toBe('upi')
            ->and((string) $rows[0]->payment_date)->toStartWith('2026-07-21');

        // 500 opening − 150 actually paid.
        expect(cusOutstanding($customer))->toBe('350.00');
    });

    it('edits a sale, recomputing the total from the lines rather than trusting the form', function () {
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business);
        $sale = cusSale($business, $owner, $customer, '500.00', '2026-07-20');
        $line = cusSaleLine($business, $sale, 10, '50.00');

        $this->actingAs($owner)
            ->patch("/customers/{$customer->id}/sales/{$sale->id}", [
                'business' => $business->id,
                'sale_date' => '2026-07-22',
                'lines' => [$line->id => ['qty' => 8, 'rate' => '45.00']],
                // Not a field the form has, and not one the server may take:
                // the total is Σ line_total or it is fiction.
                'total' => '999999.00',
            ])
            ->assertRedirect();

        [$freshSale, $freshLine] = asTenant($business->id, fn () => [
            Sale::find($sale->id), SaleLine::find($line->id),
        ]);

        expect((string) $freshSale->total)->toBe('360.00')            // 8 × 45
            ->and($freshSale->sale_date->toDateString())->toBe('2026-07-22')
            ->and((string) $freshLine->line_total)->toBe('360.00')
            // The snapshots are what the pack listed and cost that day. An edit
            // to qty must not quietly restate either.
            ->and((string) $freshLine->list_rate)->toBe('50.00')
            ->and((string) $freshLine->cost_at_sale)->toBe('30.00');

        expect(cusOutstanding($customer))->toBe('360.00');
    });

    it('deletes a sale off the khata, keeping the row so it can come back', function () {
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business);
        $sale = cusSale($business, $owner, $customer, '500.00', '2026-07-20');

        $this->actingAs($owner)
            ->delete("/customers/{$customer->id}/sales/{$sale->id}", ['business' => $business->id])
            ->assertRedirect();

        // Gone from the khata: outstanding no longer counts it, and the
        // statement no longer lists it.
        expect(cusOutstanding($customer))->toBe('0.00');
        expect(cusLedgerCount($customer))->toBe(0);

        // But still on disk, stamped — invoices.sale_id and orders.sale_id are
        // real foreign keys, and restore has to have something to restore.
        $row = Tenancy::withoutTenant(fn () => DB::table('sales')->where('id', $sale->id)->first());
        expect($row)->not->toBeNull()
            ->and($row->deleted_at)->not->toBeNull();
    });

    it('deletes a payment, putting the outstanding back', function () {
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business, 'Ramesh Kumar', '500.00');
        $payment = cusPayment($business, $owner, $customer, '200.00', '2026-07-20');

        $this->actingAs($owner)
            ->delete("/customers/{$customer->id}/payments/{$payment->id}", ['business' => $business->id])
            ->assertRedirect();

        expect(cusOutstanding($customer))->toBe('500.00');
        expect(cusLedgerCount($customer))->toBe(0);
    });

    it('bumps sync_seq on a delete, so the phones stop showing the row too', function () {
        // A device learns about rows by being sent them, never by being told one
        // vanished. A soft delete that did not bump sync_seq would leave the
        // sale in every phone's khata for good.
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business);
        $sale = cusSale($business, $owner, $customer, '500.00', '2026-07-20');
        $before = $sale->sync_seq;

        $this->actingAs($owner)
            ->delete("/customers/{$customer->id}/sales/{$sale->id}", ['business' => $business->id]);

        $row = Tenancy::withoutTenant(fn () => DB::table('sales')->where('id', $sale->id)->first());
        expect((int) $row->sync_seq)->toBeGreaterThan((int) $before);
    });

    it('restores a deleted sale to the khata', function () {
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business);
        $sale = cusSale($business, $owner, $customer, '500.00', '2026-07-20');

        $this->actingAs($owner)
            ->delete("/customers/{$customer->id}/sales/{$sale->id}", ['business' => $business->id]);

        $this->actingAs($owner)
            ->post("/customers/{$customer->id}/sales/{$sale->id}/restore", ['business' => $business->id])
            ->assertRedirect();

        expect(cusOutstanding($customer))->toBe('500.00');
        expect(cusLedgerCount($customer))->toBe(1);
    });

    it('restores a deleted payment', function () {
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business, 'Ramesh Kumar', '500.00');
        $payment = cusPayment($business, $owner, $customer, '200.00', '2026-07-20');

        $this->actingAs($owner)
            ->delete("/customers/{$customer->id}/payments/{$payment->id}", ['business' => $business->id]);
        $this->actingAs($owner)
            ->post("/customers/{$customer->id}/payments/{$payment->id}/restore", ['business' => $business->id])
            ->assertRedirect();

        expect(cusOutstanding($customer))->toBe('300.00');
    });

    it('refuses to change a sale that already carries a tax invoice', function () {
        // The invoice is in the customer's hands and carries a government
        // sequence number. Editing what it was issued against would leave a
        // filed document describing no sale in this book.
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business);
        $sale = cusSale($business, $owner, $customer, '500.00', '2026-07-20');
        cusInvoice($business, $owner, $customer, $sale);

        $this->actingAs($owner)
            ->delete("/customers/{$customer->id}/sales/{$sale->id}", ['business' => $business->id])
            ->assertSessionHas('error', __('customers.cannot_edit_invoiced'));

        $this->actingAs($owner)
            ->patch("/customers/{$customer->id}/sales/{$sale->id}", [
                'business' => $business->id, 'sale_date' => '2026-07-22',
            ])
            ->assertSessionHas('error', __('customers.cannot_edit_invoiced'));

        expect(cusOutstanding($customer))->toBe('500.00');
    });

    it('sends an order-delivered sale back to the order that owns its figures', function () {
        // Correcting the order re-issues the sale, so an edit made here would be
        // silently undone the next time anyone touched the order.
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business);
        $sale = cusSale($business, $owner, $customer, '500.00', '2026-07-20');
        cusOrder($business, $owner, $customer, $sale);

        $this->actingAs($owner)
            ->delete("/customers/{$customer->id}/sales/{$sale->id}", ['business' => $business->id])
            ->assertSessionHas('error', __('customers.cannot_edit_order_sale'));

        expect(cusOutstanding($customer))->toBe('500.00');
    });

    it('refuses to touch either half of an older reversal pair', function () {
        // The API and the order workflow still correct by appending a negated
        // mirror row. Editing either half stops the pair cancelling; deleting
        // either half revives the other's effect on the balance.
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business, 'Ramesh Kumar', '500.00');
        $payment = cusPayment($business, $owner, $customer, '200.00', '2026-07-20');

        // Written by the real reverser, so the fixture is the pair the API
        // actually produces rather than a hand-built imitation of one.
        $reversal = asTenant($business->id, function () use ($owner, $payment) {
            app()->bind('tenant.user_id', fn () => $owner->id);

            return app(LedgerReverser::class)->reversePayment(Payment::find($payment->id));
        });

        $this->actingAs($owner)
            ->delete("/customers/{$customer->id}/payments/{$reversal->id}", ['business' => $business->id])
            ->assertSessionHas('error', __('customers.cannot_edit_reversal'));

        $this->actingAs($owner)
            ->delete("/customers/{$customer->id}/payments/{$payment->id}", ['business' => $business->id])
            ->assertSessionHas('error', __('customers.cannot_edit_reversed'));

        // The pair still nets to zero: 500 opening, 200 paid, 200 reversed.
        expect(cusOutstanding($customer))->toBe('500.00');
    });

    it('offers edit and delete on the ledger, not by URL alone', function () {
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business);
        $sale = cusSale($business, $owner, $customer, '500.00', '2026-07-20');
        cusSaleLine($business, $sale, 10, '50.00');

        $this->actingAs($owner)->get("/customers/{$customer->id}?business={$business->id}")
            ->assertOk()
            ->assertSee(route('customers.sales.update', ['customer' => $customer->id, 'sale' => $sale->id]), false)
            ->assertSee(route('customers.sales.destroy', ['customer' => $customer->id, 'sale' => $sale->id]), false)
            ->assertSee(__('customers.edit_sale'))
            ->assertSee(__('customers.delete'));
    });

    it('lists a deleted entry with its way back, below the khata', function () {
        // A delete tapped on the wrong row has to be undoable, and an owner who
        // cannot see what they deleted cannot undo it.
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business);
        $sale = cusSale($business, $owner, $customer, '500.00', '2026-07-20');

        $this->actingAs($owner)
            ->delete("/customers/{$customer->id}/sales/{$sale->id}", ['business' => $business->id]);

        $this->actingAs($owner)->get("/customers/{$customer->id}?business={$business->id}")
            ->assertOk()
            ->assertSee(__('customers.deleted_heading'))
            ->assertSee(route('customers.sales.restore', ['customer' => $customer->id, 'sale' => $sale->id]), false);
    });

    it('shows the refusal on the page, not only in the session', function () {
        // A flash nobody renders is a button that appears to do nothing, and
        // the owner presses it again.
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business);
        $sale = cusSale($business, $owner, $customer, '500.00', '2026-07-20');
        cusInvoice($business, $owner, $customer, $sale);

        $this->actingAs($owner)
            ->delete("/customers/{$customer->id}/sales/{$sale->id}", ['business' => $business->id]);

        $this->actingAs($owner)->get("/customers/{$customer->id}?business={$business->id}")
            ->assertOk()
            ->assertSee(__('customers.cannot_edit_invoiced'));
    });

    it('confirms a successful correction on the page', function () {
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business, 'Ramesh Kumar', '500.00');
        $payment = cusPayment($business, $owner, $customer, '200.00', '2026-07-20');

        $this->actingAs($owner)
            ->patch("/customers/{$customer->id}/payments/{$payment->id}", [
                'business' => $business->id, 'amount' => '150.00',
                'payment_date' => '2026-07-20', 'mode' => 'cash',
            ]);

        $this->actingAs($owner)->get("/customers/{$customer->id}?business={$business->id}")
            ->assertOk()
            ->assertSee(__('customers.payment_updated'));
    });

    it('rejects an amount that is not a payment anyone could have made', function () {
        [$owner, $business] = pwOwner();
        $customer = cusCustomer($business, 'Ramesh Kumar', '500.00');
        $payment = cusPayment($business, $owner, $customer, '200.00', '2026-07-20');

        $this->actingAs($owner)
            ->patch("/customers/{$customer->id}/payments/{$payment->id}", [
                'business' => $business->id, 'amount' => '0',
                'payment_date' => '2026-07-20', 'mode' => 'cash',
            ])
            ->assertSessionHasErrors('amount');

        expect(cusOutstanding($customer))->toBe('300.00');
    });

    it('does not edit or delete another tenant\'s sale', function () {
        [$owner, $business] = pwOwner();
        [$theirOwner, $theirBusiness] = pwOwner();
        $theirCustomer = cusCustomer($theirBusiness);
        $theirSale = cusSale($theirBusiness, $theirOwner, $theirCustomer, '500.00', '2026-07-20');

        $this->actingAs($owner)
            ->delete("/customers/{$theirCustomer->id}/sales/{$theirSale->id}", ['business' => $business->id])
            ->assertNotFound();

        // Cross-tenant on purpose: the assertion IS that the other shop's row is
        // untouched, which cannot be checked from inside this tenant.
        expect(Tenancy::withoutTenant(
            fn () => DB::table('sales')->where('id', $theirSale->id)->value('deleted_at')
        ))->toBeNull();
    });
});
