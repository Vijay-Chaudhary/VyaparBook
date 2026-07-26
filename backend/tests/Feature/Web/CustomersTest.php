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
            ->assertRedirect(route('reports.dashboard', ['business' => $business->id]));
    });
});
