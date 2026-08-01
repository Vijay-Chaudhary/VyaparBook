<?php
// tests/Feature/ReminderDispatcherTest.php

use App\Jobs\SendReminderJob;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\ReminderBatch;
use App\Models\ReminderLog;
use App\Models\Sale;
use App\Models\User;
use App\Services\ReminderDispatcher;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function inDispatchTenant(string $businessId, callable $fn): mixed
{
    return DB::transaction(function () use ($businessId, $fn) {
        TenantContext::switchTo($businessId);
        app()->bind('tenant.id', fn () => $businessId);

        return $fn();
    });
}

/** A tenant with automation on and one planned reminder ready to go. */
function plannedBatch(array $settings = []): array
{
    $business = Business::factory()->create();
    $owner = User::factory()->create();
    DB::table('businesses')->where('id', $business->id)->update(
        ['reminder_auto_enabled' => true, 'reminder_send_at' => '10:00:00'] + $settings
    );

    $customer = Customer::create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ramesh Kumar', 'village' => 'Rampur',
        'phone' => '9876543210', 'opening_balance' => '0.00',
    ]);

    $sale = new Sale([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'sale_date' => now()->subDays(60)->format('Y-m-d'),
    ]);
    $sale->total = '2500.00';
    $sale->created_by = $owner->id;
    $sale->save();

    $batch = ReminderBatch::create([
        'business_id' => $business->id, 'scheduled_for' => now()->toDateString(),
        'status' => 'planned', 'planned_count' => 1,
    ]);

    $log = new ReminderLog([
        'business_id' => $business->id, 'customer_id' => $customer->id,
        'channel' => 'cloud_api', 'amount_at_send' => '2500.00',
        'locale' => 'en', 'phone_e164' => '919876543210', 'batch_id' => $batch->id,
    ]);
    $log->status = 'planned';
    $log->save();

    return [$business->fresh(), $customer, $batch, $log, $owner];
}

function dispatchFor(Business $b): void
{
    inDispatchTenant($b->id, fn () => app(ReminderDispatcher::class)->dispatchFor($b->id, Carbon::now()));
}

function reload(ReminderBatch $b): ReminderBatch
{
    return ReminderBatch::findOrFail($b->id);
}

beforeEach(function () {
    Queue::fake();
    config()->set('services.whatsapp.driver', 'cloud_api');
    Carbon::setTestNow('2026-07-25 11:00:00');   // inside quiet hours, past 10:00
});

afterEach(fn () => Carbon::setTestNow());

it('queues the planned reminders once the send time has passed', function () {
    [$business, , $batch, $log] = plannedBatch();

    dispatchFor($business);

    expect(ReminderLog::find($log->id)->status)->toBe('queued');
    expect(reload($batch)->status)->toBe('sent');
    expect(reload($batch)->sent_count)->toBe(1);
    Queue::assertPushed(SendReminderJob::class, 1);
});

it('refuses to send outside quiet hours and says so', function () {
    Carbon::setTestNow('2026-07-25 22:30:00');   // late evening
    [$business, , $batch, $log] = plannedBatch();

    dispatchFor($business);

    Queue::assertNothingPushed();
    expect(ReminderLog::find($log->id)->status)->toBe('planned');
    expect(reload($batch)->stopped_reason)->toBe('quiet_hours');
});

it('refuses to send before the tenant\'s chosen time', function () {
    Carbon::setTestNow('2026-07-25 09:15:00');   // inside quiet hours, before 10:00
    [$business, , , $log] = plannedBatch();

    dispatchFor($business);

    Queue::assertNothingPushed();
    expect(ReminderLog::find($log->id)->status)->toBe('planned');
});

it('refuses to send while the transport is still the log driver', function () {
    config()->set('services.whatsapp.driver', 'log');
    [$business, , $batch] = plannedBatch();

    dispatchFor($business);

    Queue::assertNothingPushed();
    expect(reload($batch)->stopped_reason)->toBe('transport_disabled');
});

it('stops when the tenant switched automation off after planning', function () {
    [$business, , $batch] = plannedBatch();
    DB::table('businesses')->where('id', $business->id)
        ->update(['reminder_auto_enabled' => false]);

    dispatchFor($business->fresh());

    Queue::assertNothingPushed();
    expect(reload($batch)->stopped_reason)->toBe('automation_off');
});

it('skips a row the owner cancelled', function () {
    [$business, , , $log] = plannedBatch();
    DB::table('reminder_logs')->where('id', $log->id)
        ->update(['status' => 'cancelled']);

    dispatchFor($business);

    Queue::assertNothingPushed();
    expect(ReminderLog::find($log->id)->status)->toBe('cancelled');
});

it('sends nothing when the whole batch was cancelled', function () {
    [$business, , $batch, $log] = plannedBatch();
    DB::table('reminder_batches')->where('id', $batch->id)
        ->update(['status' => 'cancelled']);

    dispatchFor($business);

    Queue::assertNothingPushed();
    expect(ReminderLog::find($log->id)->status)->toBe('planned');
});

it('does not chase a customer who paid between planning and sending', function () {
    [$business, $customer, , $log, $owner] = plannedBatch();

    // They settled up overnight — chasing them now would be the worst possible
    // bug in this feature.
    $payment = new Payment([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'payment_date' => now()->toDateString(),
        'amount' => '2500.00', 'mode' => 'cash',
    ]);
    $payment->created_by = $owner->id;
    $payment->save();

    dispatchFor($business);

    Queue::assertNothingPushed();
    expect(ReminderLog::find($log->id)->status)->toBe('skipped');
});

it('does not send to someone who opted out after planning', function () {
    [$business, $customer, , $log] = plannedBatch();
    $customer->reminder_opt_out_at = now();
    $customer->save();

    dispatchFor($business);

    Queue::assertNothingPushed();
    expect(ReminderLog::find($log->id)->status)->toBe('skipped');
});
