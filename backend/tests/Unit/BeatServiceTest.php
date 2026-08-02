<?php

use App\Models\Beat;
use App\Models\BeatCustomer;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Services\BeatService;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function inBeatTenant(string $businessId, callable $fn): mixed
{
    return DB::transaction(function () use ($businessId, $fn) {
        TenantContext::switchTo($businessId);
        app()->bind('tenant.id', fn () => $businessId);

        return $fn();
    });
}

function makeBeat(Business $b, string $name, array $weekdays, ?int $userId = null): Beat
{
    return Beat::create([
        'business_id' => $b->id, 'name' => $name,
        'weekdays' => $weekdays, 'assigned_user_id' => $userId,
    ]);
}

function addToBeat(Beat $beat, string $customerName, int $position): Customer
{
    $customer = Customer::create([
        'business_id' => $beat->business_id, 'uuid' => (string) Str::uuid(),
        'name' => $customerName, 'village' => 'Rampur', 'opening_balance' => '0.00',
    ]);

    BeatCustomer::create([
        'business_id' => $beat->business_id, 'beat_id' => $beat->id,
        'customer_id' => $customer->id, 'position' => $position,
    ]);

    return $customer;
}

/** @return array<int, string> */
function beatNamesFor(Business $b, string $date, ?int $userId = null): array
{
    return inBeatTenant($b->id, fn () => app(BeatService::class)
        ->forDate($b->id, Carbon::parse($date), $userId)->pluck('name')->all());
}

it('returns only the beats scheduled for that weekday', function () {
    $b = Business::factory()->create();
    makeBeat($b, 'Rampur', [1, 4]);      // Mon + Thu
    makeBeat($b, 'Sitapur', [2]);        // Tue

    // 2026-07-27 is a Monday.
    expect(beatNamesFor($b, '2026-07-27'))->toBe(['Rampur']);
    expect(beatNamesFor($b, '2026-07-28'))->toBe(['Sitapur']);   // Tuesday
    expect(beatNamesFor($b, '2026-07-30'))->toBe(['Rampur']);    // Thursday
});

it('returns nothing on a day no beat runs', function () {
    $b = Business::factory()->create();
    makeBeat($b, 'Rampur', [1]);

    expect(beatNamesFor($b, '2026-07-26'))->toBe([]);   // Sunday
});

it('filters to one salesman when asked', function () {
    $b = Business::factory()->create();
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    makeBeat($b, 'Mine', [1], $mine->id);
    makeBeat($b, 'Theirs', [1], $theirs->id);
    makeBeat($b, 'Unassigned', [1], null);

    expect(beatNamesFor($b, '2026-07-27', $mine->id))->toBe(['Mine']);
    // Without a filter the owner sees everything scheduled today.
    expect(beatNamesFor($b, '2026-07-27'))->toBe(['Mine', 'Theirs', 'Unassigned']);
});

it('lists a beat\'s customers in call order', function () {
    $b = Business::factory()->create();
    $beat = makeBeat($b, 'Rampur', [1]);
    addToBeat($beat, 'Third', 3);
    addToBeat($beat, 'First', 1);
    addToBeat($beat, 'Second', 2);

    $names = inBeatTenant($b->id, fn () => app(BeatService::class)
        ->forDate($b->id, Carbon::parse('2026-07-27'))
        ->first()->beatCustomers->map(fn ($bc) => $bc->customer->name)->all());

    expect($names)->toBe(['First', 'Second', 'Third']);
});

it('ignores an archived beat', function () {
    $b = Business::factory()->create();
    $beat = makeBeat($b, 'Old Route', [1]);
    $beat->archived_at = now();
    $beat->save();

    expect(beatNamesFor($b, '2026-07-27'))->toBe([]);
});

it('never returns another tenant\'s beats', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    makeBeat($mine, 'Mine', [1]);
    makeBeat($theirs, 'Theirs', [1]);

    expect(beatNamesFor($mine, '2026-07-27'))->toBe(['Mine']);
});

it('stamps a sync_seq so beats stream down the delta pull', function () {
    $b = Business::factory()->create();
    $beat = makeBeat($b, 'Rampur', [1]);

    expect($beat->sync_seq)->toBeGreaterThan(0);
});
