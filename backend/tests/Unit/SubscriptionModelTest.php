<?php
// tests/Unit/SubscriptionModelTest.php

use App\Models\Business;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('has a uuid primary key and round-trips trial_ends_at as a datetime', function () {
    $business = Business::factory()->create();

    $sub = Subscription::create([
        'business_id' => $business->id,
        'plan' => 'free',
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(14),
        'current_period_end' => null,
    ]);

    expect($sub->id)->toBeString()->toHaveLength(36);
    expect($sub->fresh()->trial_ends_at)->toBeInstanceOf(Carbon::class);
    expect($sub->version)->toBe(1);
});

it('allows only one subscription per business', function () {
    $business = Business::factory()->create();

    Subscription::create([
        'business_id' => $business->id,
        'plan' => 'free',
        'status' => 'trialing',
        'trial_ends_at' => now()->addDays(14),
    ]);

    expect(fn () => Subscription::create([
        'business_id' => $business->id,
        'plan' => 'pro',
        'status' => 'active',
    ]))->toThrow(QueryException::class);
});
