<?php
// tests/Feature/Web/RemindersTest.php
//
// pwOwner comes from tests/Pest.php.

use App\Models\Business;
use App\Models\Customer;
use App\Models\ReminderLog;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** A customer with an unpaid sale old enough to be overdue at the defaults. */
function remOverdueCustomer(Business $b, User $u, string $name, string $total = '2000.00', ?string $phone = '9876543210'): Customer
{
    $c = Customer::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'village' => 'Rampur', 'phone' => $phone, 'opening_balance' => '0.00',
    ]);

    $s = new Sale([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'sale_date' => now()->subDays(60)->format('Y-m-d'),
    ]);
    $s->setConnection('pgsql_migrate');
    $s->total = $total;          // not fillable — SaleWriter stamps it in prod
    $s->created_by = $u->id;
    $s->save();

    return $c;
}

describe('access', function () {
    it('redirects a guest to login', function () {
        $this->get('/reminders')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $this->actingAs(User::factory()->create())
            ->get('/reminders')->assertRedirect(route('app'));
    });

    it('refuses an owner asking for a business they do not own', function () {
        [$owner] = pwOwner();
        [, $theirs] = pwOwner();

        $this->actingAs($owner)->get('/reminders?business=' . $theirs->id)
            ->assertRedirect(route('app'));
    });
});

describe('render', function () {
    it('lists an overdue customer with the amount owed', function () {
        [$owner, $business] = pwOwner();
        remOverdueCustomer($business, $owner, 'Ramesh Kumar', '2500.00');

        $this->actingAs($owner)->get('/reminders?business=' . $business->id)
            ->assertOk()
            ->assertSee(__('reminders.heading'))
            ->assertSee('Ramesh Kumar')
            ->assertSee('₹2,500.00')
            ->assertSee(__('reminders.remind'));
    });

    it('omits a customer who is under the threshold', function () {
        [$owner, $business] = pwOwner();
        remOverdueCustomer($business, $owner, 'Small Fry', '100.00');

        $this->actingAs($owner)->get('/reminders?business=' . $business->id)
            ->assertOk()
            ->assertDontSee('Small Fry');
    });

    it('shows why a customer without a phone cannot be reminded', function () {
        [$owner, $business] = pwOwner();
        remOverdueCustomer($business, $owner, 'No Phone', '2000.00', phone: null);

        $this->actingAs($owner)->get('/reminders?business=' . $business->id)
            ->assertOk()
            ->assertSee('No Phone')
            ->assertSee(__('reminders.blocked.no_phone'));
    });
});

describe('send', function () {
    it('logs the reminder once and redirects to a prefilled wa.me link', function () {
        [$owner, $business] = pwOwner();
        $customer = remOverdueCustomer($business, $owner, 'Ramesh Kumar', '2500.00');

        $response = $this->actingAs($owner)
            ->post('/reminders/' . $customer->id, ['business' => $business->id]);

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        expect($target)->toStartWith('https://wa.me/919876543210?text=');
        expect(urldecode($target))->toContain($business->name);
        expect(urldecode($target))->toContain('₹2,500.00');

        $logs = ReminderLog::on('pgsql_migrate')->where('business_id', $business->id)->get();
        expect($logs)->toHaveCount(1);
        expect($logs[0]->channel)->toBe('wa_link');
        expect((string) $logs[0]->amount_at_send)->toBe('2500.00');
        expect($logs[0]->phone_e164)->toBe('919876543210');
        expect($logs[0]->created_by)->toBe($owner->id);
    });

    it('refuses to send to an opted-out customer and writes no log', function () {
        [$owner, $business] = pwOwner();
        $customer = remOverdueCustomer($business, $owner, 'Opted Out');
        $customer->reminder_opt_out_at = Carbon::now();
        $customer->save();

        $this->actingAs($owner)
            ->post('/reminders/' . $customer->id, ['business' => $business->id])
            ->assertRedirect(route('reminders', ['business' => $business->id]));

        expect(ReminderLog::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(0);
    });

    it('refuses a customer whose phone cannot be dialled', function () {
        [$owner, $business] = pwOwner();
        $customer = remOverdueCustomer($business, $owner, 'Bad Phone', phone: '12345');

        $this->actingAs($owner)
            ->post('/reminders/' . $customer->id, ['business' => $business->id])
            ->assertRedirect(route('reminders', ['business' => $business->id]));

        expect(ReminderLog::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(0);
    });

    it('does not accept another tenant\'s customer', function () {
        [$owner, $business] = pwOwner();
        [$otherOwner, $otherBusiness] = pwOwner();
        $theirs = remOverdueCustomer($otherBusiness, $otherOwner, 'Not Yours');

        $this->actingAs($owner)
            ->post('/reminders/' . $theirs->id, ['business' => $business->id])
            ->assertNotFound();

        expect(ReminderLog::on('pgsql_migrate')->count())->toBe(0);
    });
});

describe('opt out', function () {
    it('stops and then re-allows reminders for a customer', function () {
        [$owner, $business] = pwOwner();
        $customer = remOverdueCustomer($business, $owner, 'Ramesh Kumar');

        $this->actingAs($owner)
            ->post('/reminders/' . $customer->id . '/opt-out', ['business' => $business->id])
            ->assertRedirect(route('reminders', ['business' => $business->id]));

        expect(Customer::on('pgsql_migrate')->find($customer->id)->reminder_opt_out_at)->not->toBeNull();

        $this->actingAs($owner)->get('/reminders?business=' . $business->id)
            ->assertOk()
            ->assertSee(__('reminders.blocked.opted_out'));

        $this->actingAs($owner)
            ->post('/reminders/' . $customer->id . '/opt-in', ['business' => $business->id]);

        expect(Customer::on('pgsql_migrate')->find($customer->id)->reminder_opt_out_at)->toBeNull();
    });
});
