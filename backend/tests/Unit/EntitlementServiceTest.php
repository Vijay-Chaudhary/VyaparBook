<?php
// tests/Unit/EntitlementServiceTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => Carbon::setTestNow('2026-07-18 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

$sub = fn (Business $b, array $attrs) => new Subscription(array_merge(['business_id' => $b->id], $attrs));

$seedCustomers = function (Business $b, int $n): void {
    for ($i = 0; $i < $n; $i++) {
        Customer::on('pgsql_migrate')->create([
            'business_id' => $b->id,
            'uuid' => (string) Str::uuid(),
            'name' => "C{$i}",
            'opening_balance' => '0.00',
        ]);
    }
};

$inTenant = function (Business $b, callable $fn) {
    return DB::transaction(function () use ($b, $fn) {
        TenantContext::switchTo($b->id);
        app()->bind('tenant.id', fn () => $b->id);

        return $fn();
    });
};

it('treats a live trial as pro with its feature and unlimited customers', function () use ($sub) {
    $business = Business::factory()->create();
    $trial = $sub($business, ['plan' => 'free', 'status' => 'trialing', 'trial_ends_at' => now()->addDays(14)]);
    $svc = new EntitlementService();

    expect($svc->effectivePlan($trial))->toBe('pro');
    expect($svc->hasFeature($trial, 'stock_production'))->toBeTrue();
    expect($svc->isOverLimit($trial, 'customers'))->toBeFalse(); // unlimited — no count needed
    expect($svc->trialDaysLeft($trial))->toBe(14);
    expect($svc->mayWrite($trial))->toBeTrue();
});

it('floors an expired trial to free with no feature and zero days left', function () use ($sub) {
    $business = Business::factory()->create();
    $expired = $sub($business, ['plan' => 'free', 'status' => 'past_due', 'trial_ends_at' => now()->subDay()]);
    $svc = new EntitlementService();

    expect($svc->effectivePlan($expired))->toBe('free');
    expect($svc->hasFeature($expired, 'stock_production'))->toBeFalse();
    expect($svc->trialDaysLeft($expired))->toBe(0);
});

it('flags customers over the free limit at 50 and not at 49', function () use ($sub, $seedCustomers, $inTenant) {
    $svc = new EntitlementService();

    $atCap = Business::factory()->create();
    $seedCustomers($atCap, 50);
    $overAtCap = $inTenant($atCap, fn () => $svc->isOverLimit(
        $sub($atCap, ['plan' => 'free', 'status' => 'past_due', 'trial_ends_at' => now()->subDay()]), 'customers'
    ));
    expect($overAtCap)->toBeTrue();

    $underCap = Business::factory()->create();
    $seedCustomers($underCap, 49);
    $overUnderCap = $inTenant($underCap, fn () => $svc->isOverLimit(
        $sub($underCap, ['plan' => 'free', 'status' => 'past_due', 'trial_ends_at' => now()->subDay()]), 'customers'
    ));
    expect($overUnderCap)->toBeFalse();
});

it('flags users over the free limit once the single seat is taken', function () use ($sub, $inTenant) {
    $svc = new EntitlementService();
    $business = Business::factory()->create();
    $freeSub = fn () => $sub($business, ['plan' => 'free', 'status' => 'past_due', 'trial_ends_at' => now()->subDay()]);

    $overEmpty = $inTenant($business, fn () => $svc->isOverLimit($freeSub(), 'users'));
    expect($overEmpty)->toBeFalse();

    Membership::on('pgsql_migrate')->create([
        'user_id' => User::factory()->create()->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    $overSeated = $inTenant($business, fn () => $svc->isOverLimit($freeSub(), 'users'));
    expect($overSeated)->toBeTrue();
});

it('blocks writes only for read_only and canceled', function () use ($sub) {
    $business = Business::factory()->create();
    $svc = new EntitlementService();

    expect($svc->mayWrite($sub($business, ['status' => 'read_only'])))->toBeFalse();
    expect($svc->mayWrite($sub($business, ['status' => 'canceled'])))->toBeFalse();
    expect($svc->mayWrite($sub($business, ['status' => 'past_due'])))->toBeTrue();
});
