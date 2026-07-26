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

    it('links an overdue customer through to their khata', function () {
        [$owner, $business] = pwOwner();
        $customer = remOverdueCustomer($business, $owner, 'Ramesh Kumar', '2500.00');

        // Seeing what someone owes raises the question of what it is made of,
        // and this list was the only place that could not answer it.
        $this->actingAs($owner)->get('/reminders?business=' . $business->id)
            ->assertOk()
            ->assertSee(route('customers.show', [
                'customer' => $customer->id, 'business' => $business->id,
            ]), false);
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

describe('cloud api transport (Phase 4b)', function () {
    it('keeps the 4a wa.me handoff under the default log driver', function () {
        [$owner, $business] = pwOwner();
        $customer = remOverdueCustomer($business, $owner, 'Ramesh Kumar', '2500.00');

        // The whole point of shipping dark: default config, 4a behaviour.
        $response = $this->actingAs($owner)
            ->post('/reminders/' . $customer->id, ['business' => $business->id]);

        expect($response->headers->get('Location'))->toStartWith('https://wa.me/');
    });

    it('queues a cloud_api send and stays in the app when the driver is switched on', function () {
        config()->set('services.whatsapp.driver', 'cloud_api');
        Illuminate\Support\Facades\Queue::fake();

        [$owner, $business] = pwOwner();
        $customer = remOverdueCustomer($business, $owner, 'Ramesh Kumar', '2500.00');

        $this->actingAs($owner)
            ->post('/reminders/' . $customer->id, ['business' => $business->id])
            ->assertRedirect(route('reminders', ['business' => $business->id]));

        $log = ReminderLog::on('pgsql_migrate')->where('business_id', $business->id)->sole();
        expect($log->channel)->toBe('cloud_api');
        expect($log->status)->toBe('queued');          // written BEFORE dispatch
        expect((string) $log->amount_at_send)->toBe('2500.00');

        Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\SendReminderJob::class, function ($job) use ($log, $business) {
            return $job->reminderLogId === $log->id && $job->businessId === $business->id;
        });
    });

    it('still refuses an opted-out customer on the cloud api path', function () {
        config()->set('services.whatsapp.driver', 'cloud_api');
        Illuminate\Support\Facades\Queue::fake();

        [$owner, $business] = pwOwner();
        $customer = remOverdueCustomer($business, $owner, 'Opted Out');
        $customer->reminder_opt_out_at = Carbon::now();
        $customer->save();

        $this->actingAs($owner)
            ->post('/reminders/' . $customer->id, ['business' => $business->id])
            ->assertRedirect(route('reminders', ['business' => $business->id]));

        expect(ReminderLog::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(0);
        Illuminate\Support\Facades\Queue::assertNothingPushed();
    });
});

it('shows the outcome of the last reminder on the row', function () {
    [$owner, $business] = pwOwner();
    $customer = remOverdueCustomer($business, $owner, 'Ramesh Kumar', '2500.00');

    $log = new ReminderLog([
        'business_id' => $business->id, 'customer_id' => $customer->id,
        'channel' => 'cloud_api', 'amount_at_send' => '2500.00',
        'locale' => 'en', 'phone_e164' => '919876543210',
    ]);
    $log->setConnection('pgsql_migrate');
    $log->created_by = $owner->id;
    $log->status = 'failed';
    $log->save();

    $this->actingAs($owner)->get('/reminders?business=' . $business->id)
        ->assertOk()
        ->assertSee(__('reminders.status.failed'));
});

describe('scheduled reminders (Phase 4c)', function () {
    it('saves the automation settings and states what enabling means', function () {
        [$owner, $business] = pwOwner();

        $this->actingAs($owner)->post('/reminders/settings', [
            'business' => $business->id,
            'reminder_min_days' => '7',
            'reminder_min_outstanding' => '500',
            'reminder_auto_enabled' => '1',
            'reminder_send_at' => '11:30',
            'reminder_cooldown_days' => '10',
            'reminder_daily_cap' => '40',
        ])->assertRedirect(route('reminders', ['business' => $business->id]));

        $fresh = App\Models\Business::on('pgsql_migrate')->find($business->id);
        expect($fresh->reminder_min_days)->toBe(7);
        expect($fresh->reminder_auto_enabled)->toBeTrue();
        expect($fresh->reminder_cooldown_days)->toBe(10);
        expect($fresh->reminder_daily_cap)->toBe(40);

        $this->actingAs($owner)->get('/reminders?business=' . $business->id)
            ->assertOk()
            ->assertSee(__('reminders.auto_warning'));
    });

    it('refuses a send time outside quiet hours', function () {
        [$owner, $business] = pwOwner();

        $this->actingAs($owner)->post('/reminders/settings', [
            'business' => $business->id,
            'reminder_min_days' => '7',
            'reminder_min_outstanding' => '500',
            'reminder_send_at' => '03:00',
            'reminder_cooldown_days' => '7',
            'reminder_daily_cap' => '25',
        ])->assertSessionHasErrors('reminder_send_at');
    });

    it('rejects an absurd daily cap', function () {
        [$owner, $business] = pwOwner();

        $this->actingAs($owner)->post('/reminders/settings', [
            'business' => $business->id,
            'reminder_min_days' => '7',
            'reminder_min_outstanding' => '500',
            'reminder_send_at' => '10:00',
            'reminder_cooldown_days' => '7',
            'reminder_daily_cap' => '5000',
        ])->assertSessionHasErrors('reminder_daily_cap');
    });

    it('shows tomorrow\'s planned reminders so the owner can cancel them', function () {
        [$owner, $business] = pwOwner();
        $customer = remOverdueCustomer($business, $owner, 'Ramesh Kumar', '2500.00');

        $batch = App\Models\ReminderBatch::on('pgsql_migrate')->create([
            'business_id' => $business->id, 'scheduled_for' => now()->toDateString(),
            'status' => 'planned', 'planned_count' => 1,
        ]);
        $log = new ReminderLog([
            'business_id' => $business->id, 'customer_id' => $customer->id,
            'channel' => 'cloud_api', 'amount_at_send' => '2500.00',
            'locale' => 'en', 'phone_e164' => '919876543210', 'batch_id' => $batch->id,
        ]);
        $log->setConnection('pgsql_migrate');
        $log->status = 'planned';
        $log->save();

        $this->actingAs($owner)->get('/reminders?business=' . $business->id)
            ->assertOk()
            ->assertSee(__('reminders.scheduled_heading'))
            ->assertSee('Ramesh Kumar');

        $this->actingAs($owner)->post('/reminders/planned/' . $log->id . '/cancel', [
            'business' => $business->id,
        ])->assertRedirect(route('reminders', ['business' => $business->id]));

        expect(ReminderLog::on('pgsql_migrate')->find($log->id)->status)->toBe('cancelled');
    });

    it('does not let one tenant cancel another tenant\'s planned reminder', function () {
        [$owner, $business] = pwOwner();
        [$otherOwner, $otherBusiness] = pwOwner();
        $customer = remOverdueCustomer($otherBusiness, $otherOwner, 'Not Yours');

        $batch = App\Models\ReminderBatch::on('pgsql_migrate')->create([
            'business_id' => $otherBusiness->id, 'scheduled_for' => now()->toDateString(),
            'status' => 'planned', 'planned_count' => 1,
        ]);
        $log = new ReminderLog([
            'business_id' => $otherBusiness->id, 'customer_id' => $customer->id,
            'channel' => 'cloud_api', 'amount_at_send' => '2500.00',
            'locale' => 'en', 'phone_e164' => '919876543210', 'batch_id' => $batch->id,
        ]);
        $log->setConnection('pgsql_migrate');
        $log->status = 'planned';
        $log->save();

        $this->actingAs($owner)->post('/reminders/planned/' . $log->id . '/cancel', [
            'business' => $business->id,
        ])->assertNotFound();

        // Read past the tenant scope: the row belongs to the OTHER tenant, which
        // is precisely why the request 404'd.
        $row = Illuminate\Support\Facades\DB::connection('pgsql_migrate')
            ->table('reminder_logs')->where('id', $log->id)->first();
        expect($row->status)->toBe('planned');
    });

    it('lets a manual tap chase someone the cooldown would have skipped', function () {
        // The cooldown restrains the machine, not the owner: a human deciding to
        // chase again is a different act.
        [$owner, $business] = pwOwner();
        $customer = remOverdueCustomer($business, $owner, 'Recently Auto-Reminded');

        $batch = App\Models\ReminderBatch::on('pgsql_migrate')->create([
            'business_id' => $business->id, 'scheduled_for' => now()->subDay()->toDateString(),
            'status' => 'sent', 'planned_count' => 1, 'sent_count' => 1,
        ]);
        $log = new ReminderLog([
            'business_id' => $business->id, 'customer_id' => $customer->id,
            'channel' => 'cloud_api', 'amount_at_send' => '2500.00',
            'locale' => 'en', 'phone_e164' => '919876543210', 'batch_id' => $batch->id,
        ]);
        $log->setConnection('pgsql_migrate');
        $log->status = 'sent';
        $log->save();

        $response = $this->actingAs($owner)
            ->post('/reminders/' . $customer->id, ['business' => $business->id]);

        expect($response->headers->get('Location'))->toStartWith('https://wa.me/');
    });
});

it('lets the shop change what counts as overdue', function () {
    [$owner, $business] = pwOwner();

    $this->actingAs($owner)->post('/reminders/settings', [
        'business' => $business->id,
        'reminder_min_days' => '3',
        'reminder_min_outstanding' => '250.50',
        'reminder_send_at' => '10:00',
        'reminder_cooldown_days' => '7',
        'reminder_daily_cap' => '25',
    ])->assertRedirect(route('reminders', ['business' => $business->id]));

    $fresh = App\Models\Business::on('pgsql_migrate')->find($business->id);
    expect($fresh->reminder_min_days)->toBe(3);
    expect((string) $fresh->reminder_min_outstanding)->toBe('250.50');
});

it('rejects a nonsensical overdue window', function () {
    [$owner, $business] = pwOwner();

    $this->actingAs($owner)->post('/reminders/settings', [
        'business' => $business->id,
        'reminder_min_days' => '0',
        'reminder_min_outstanding' => '500',
        'reminder_send_at' => '10:00',
        'reminder_cooldown_days' => '7',
        'reminder_daily_cap' => '25',
    ])->assertSessionHasErrors('reminder_min_days');
});
