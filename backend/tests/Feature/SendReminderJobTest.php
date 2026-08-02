<?php
// tests/Feature/SendReminderJobTest.php

use App\Jobs\SendReminderJob;
use App\Models\Customer;
use App\Models\ReminderLog;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** A queued cloud_api reminder row for a customer who owes money. */
function queuedReminder(array $overrides = []): array
{
    [$owner, $business] = pwOwner();

    $customer = Customer::create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ramesh Kumar', 'village' => 'Rampur',
        'phone' => '9876543210', 'opening_balance' => '0.00',
    ] + $overrides);

    $sale = new Sale([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'sale_date' => now()->subDays(60)->format('Y-m-d'),
    ]);
    $sale->total = '2500.00';
    $sale->created_by = $owner->id;
    $sale->save();

    $log = new ReminderLog([
        'business_id' => $business->id,
        'customer_id' => $customer->id,
        'channel' => 'cloud_api',
        'amount_at_send' => '2500.00',
        'locale' => 'en',
        'phone_e164' => '919876543210',
    ]);
    $log->created_by = $owner->id;
    $log->save();

    return [$business, $customer, $log];
}

/**
 * Re-read a reminder under its OWN tenant.
 *
 * Binds explicitly rather than trusting whatever is ambient: the job binds a
 * tenant while it runs, so a read after dispatch would work by luck, but a read
 * before it (the "queued" assertion) has nothing bound at all.
 */
function freshLog(ReminderLog $log): ReminderLog
{
    return asTenant($log->business_id, fn () => ReminderLog::findOrFail($log->id));
}

beforeEach(function () {
    config()->set('services.whatsapp.driver', 'cloud_api');
    config()->set('services.whatsapp.phone_number_id', '11112222');
    config()->set('services.whatsapp.token', 'test-token');
});

it('marks the row sent and records the provider message id', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK1']]], 200)]);
    [$business, , $log] = queuedReminder();

    expect(freshLog($log)->status)->toBe('queued');   // written before dispatch

    dispatch_sync(new SendReminderJob($log->id, $business->id));

    $fresh = freshLog($log);
    expect($fresh->status)->toBe('sent');
    expect($fresh->provider_message_id)->toBe('wamid.OK1');
    expect($fresh->status_at)->not->toBeNull();
});

it('records a permanent failure without retrying it', function () {
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['code' => 131026, 'message' => 'Message undeliverable'],
    ], 400)]);
    [$business, , $log] = queuedReminder();

    dispatch_sync(new SendReminderJob($log->id, $business->id));

    $fresh = freshLog($log);
    expect($fresh->status)->toBe('failed');
    expect($fresh->error_code)->toBe('131026');
    expect($fresh->error_message)->toContain('undeliverable');
    Http::assertSentCount(1);   // not retried in-handle
});

it('aborts without sending when the customer opted out after the owner tapped', function () {
    Http::fake();
    [$business, $customer, $log] = queuedReminder();

    // The gap this closes: approved at 10:00, worker picks it up at 10:05, and
    // in between the customer said stop.
    $customer->reminder_opt_out_at = now();
    $customer->save();

    dispatch_sync(new SendReminderJob($log->id, $business->id));

    Http::assertNothingSent();
    expect(freshLog($log)->status)->toBe('failed');
    expect(freshLog($log)->error_code)->toBe('opted_out');
});

it('sends nothing at all under the default log driver', function () {
    config()->set('services.whatsapp.driver', 'log');
    Http::preventStrayRequests();
    [$business, , $log] = queuedReminder();

    dispatch_sync(new SendReminderJob($log->id, $business->id));

    Http::assertNothingSent();
    $fresh = freshLog($log);
    expect($fresh->status)->toBe('sent');
    expect($fresh->provider_message_id)->toStartWith('log-');
});

it('ignores a row that has already left queued, so a replayed job cannot double-send', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK1']]], 200)]);
    [$business, , $log] = queuedReminder();

    dispatch_sync(new SendReminderJob($log->id, $business->id));
    dispatch_sync(new SendReminderJob($log->id, $business->id));   // replay

    Http::assertSentCount(1);
});
