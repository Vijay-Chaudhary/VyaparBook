<?php
// tests/Feature/Web/CustomersTest.php
//
// pwOwner comes from tests/Pest.php.

use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Str;

/** A customer seeded on the migration connection, like the other seed helpers. */
function cusCustomer(Business $b, string $name = 'Ramesh Kumar', string $opening = '0.00'): Customer
{
    return Customer::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'village' => 'Rampur', 'phone' => '9876543210',
        'opening_balance' => $opening,
    ]);
}

function cusSale(Business $b, User $u, Customer $c, string $total, string $date): Sale
{
    $s = new Sale([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'sale_date' => $date,
    ]);
    $s->setConnection('pgsql_migrate');
    $s->total = $total;          // not fillable — SaleWriter stamps it in prod
    $s->created_by = $u->id;
    $s->save();

    return $s;
}

function cusPayment(Business $b, User $u, Customer $c, string $amount, string $date): Payment
{
    $p = new Payment([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'payment_date' => $date, 'amount' => $amount, 'mode' => 'cash',
    ]);
    $p->setConnection('pgsql_migrate');
    $p->created_by = $u->id;
    $p->save();

    return $p;
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

        $row = Customer::on('pgsql_migrate')->where('business_id', $business->id)
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

        expect(Customer::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(0);
    });

    it('is idempotent on a replayed uuid, so a double submit adds one row', function () {
        [$owner, $business] = pwOwner();
        $uuid = (string) Str::uuid();

        foreach ([1, 2] as $_) {
            $this->actingAs($owner)->post('/customers', [
                'business' => $business->id, 'uuid' => $uuid, 'name' => 'Double Tap',
            ]);
        }

        expect(Customer::on('pgsql_migrate')->where('business_id', $business->id)
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

        expect(Customer::on('pgsql_migrate')->where('business_id', $business->id)
            ->where('name', 'Santosh Singh')->count())->toBe(2);
    });
});

describe('update', function () {
    it('edits a customer, so a missing phone can be filled in later', function () {
        // A customer with no phone is blocked from reminders as `no_phone`;
        // this is how the owner unblocks them.
        [$owner, $business] = pwOwner();
        $c = cusCustomer($business, 'No Phone Yet');
        $c->setConnection('pgsql_migrate');
        $c->phone = null;
        $c->save();

        $this->actingAs($owner)->patch('/customers/' . $c->id, [
            'business' => $business->id,
            'name' => 'Phone Added',
            'village' => 'Mathauli',
            'phone' => '9123456780',
        ])->assertRedirect(route('customers.show', ['customer' => $c->id, 'business' => $business->id]));

        $fresh = Customer::on('pgsql_migrate')->find($c->id);
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

        $fresh = Customer::on('pgsql_migrate')->find($c->id);
        $fresh->setConnection('pgsql_migrate');
        expect(app(App\Services\KhataService::class)->outstandingFor($fresh))->toBe('1000.00');
    });

    it('refuses to edit another tenant\'s customer', function () {
        [$owner, $business] = pwOwner();
        [, $other] = pwOwner();
        $foreign = cusCustomer($other, 'Not Yours');

        $this->actingAs($owner)->patch('/customers/' . $foreign->id, [
            'business' => $business->id, 'name' => 'Hijacked',
        ]);

        // withoutGlobalScopes: the request left tenant.id bound in the container,
        // so BelongsToTenant would otherwise hide the other tenant's row entirely.
        expect(Customer::on('pgsql_migrate')->withoutGlobalScopes()->find($foreign->id)->name)
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

        $fresh = Customer::on('pgsql_migrate')->find($c->id);
        expect($fresh)->not->toBeNull()                 // the row survives
            ->and($fresh->archived_at)->not->toBeNull();

        // and the sale it carried is still there to be counted
        expect(Sale::on('pgsql_migrate')->where('customer_id', $c->id)->count())->toBe(1);
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

        expect(Customer::on('pgsql_migrate')->find($c->id)->archived_at)->toBeNull();
    });

    it('refuses to archive another tenant\'s customer', function () {
        [$owner, $business] = pwOwner();
        [, $other] = pwOwner();
        $foreign = cusCustomer($other, 'Not Yours Either');

        $this->actingAs($owner)->delete('/customers/' . $foreign->id, ['business' => $business->id]);

        expect(Customer::on('pgsql_migrate')->withoutGlobalScopes()->find($foreign->id)->archived_at)
            ->toBeNull();
    });
});
