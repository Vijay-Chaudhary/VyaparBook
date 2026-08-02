<?php
// tests/Feature/ReminderCommandsTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\ReminderBatch;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function commandTenant(bool $autoEnabled): array
{
    $business = Business::factory()->create();
    $owner = User::factory()->create();
    DB::table('businesses')->where('id', $business->id)
        ->update(['reminder_auto_enabled' => $autoEnabled]);

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

    return [$business, $customer];
}

beforeEach(fn () => Carbon::setTestNow('2026-07-25 06:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('plans only for tenants that opted in', function () {
    [$optedIn] = commandTenant(autoEnabled: true);
    [$optedOut] = commandTenant(autoEnabled: false);

    $this->artisan('reminders:plan')->assertSuccessful();

    expect(ReminderBatch::where('business_id', $optedIn->id)->count())->toBe(1);
    expect(ReminderBatch::where('business_id', $optedOut->id)->count())->toBe(0);
});

it('succeeds and says so when nobody has enabled automation', function () {
    commandTenant(autoEnabled: false);

    $this->artisan('reminders:plan')
        ->expectsOutputToContain('No tenants have reminder automation enabled.')
        ->assertSuccessful();
});

it('is safe to run twice — the second pass plans nothing new', function () {
    [$business] = commandTenant(autoEnabled: true);

    $this->artisan('reminders:plan')->assertSuccessful();
    $this->artisan('reminders:plan')->assertSuccessful();

    expect(ReminderBatch::where('business_id', $business->id)->count())->toBe(1);
});

it('dispatches nothing while the transport is still the log driver', function () {
    Queue::fake();
    config()->set('services.whatsapp.driver', 'log');
    commandTenant(autoEnabled: true);

    $this->artisan('reminders:plan')->assertSuccessful();
    Carbon::setTestNow('2026-07-25 11:00:00');
    $this->artisan('reminders:dispatch')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('dispatches a planned batch once the transport is switched on', function () {
    Queue::fake();
    config()->set('services.whatsapp.driver', 'cloud_api');
    commandTenant(autoEnabled: true);

    $this->artisan('reminders:plan')->assertSuccessful();
    Carbon::setTestNow('2026-07-25 11:00:00');
    $this->artisan('reminders:dispatch')->assertSuccessful();

    Queue::assertPushed(App\Jobs\SendReminderJob::class, 1);
});
