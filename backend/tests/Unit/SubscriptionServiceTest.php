<?php
// tests/Unit/SubscriptionServiceTest.php

use App\Models\Business;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => Carbon::setTestNow('2026-07-18 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

$inTenant = function (Business $b, int $userId, callable $fn) {
    return DB::transaction(function () use ($b, $userId, $fn) {
        TenantContext::switchTo($b->id);
        app()->bind('tenant.id', fn () => $b->id);
        app()->bind('tenant.user_id', fn () => $userId);

        return $fn();
    });
};

$payment = fn (Business $b, int $months) => SubscriptionPayment::create([
    'business_id' => $b->id,
    'uuid' => (string) Str::uuid(),
    'plan' => 'pro',
    'amount' => '499.00',
    'gst_amount' => '89.82',
    'mode' => 'upi',
    'period_months' => $months,
    'status' => 'pending',
]);

it('provisions a single 14-day trial idempotently', function () use ($inTenant) {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $svc = new SubscriptionService();

    [$first, $second] = $inTenant($business, $user->id, fn () => [
        $svc->provisionTrial($business->id),
        $svc->provisionTrial($business->id),
    ]);

    expect($first->id)->toBe($second->id);
    expect(Subscription::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(1);

    $row = Subscription::on('pgsql_migrate')->where('business_id', $business->id)->first();
    expect($row->status)->toBe('trialing');
    expect($row->trial_ends_at->timestamp)->toEqualWithDelta(now()->addDays(14)->timestamp, 60);
});

it('flips an expired trial to past_due and bumps version', function () use ($inTenant) {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $svc = new SubscriptionService();

    $result = $inTenant($business, $user->id, function () use ($svc, $business) {
        $sub = Subscription::create([
            'business_id' => $business->id, 'plan' => 'free',
            'status' => 'trialing', 'trial_ends_at' => now()->subDay(),
        ]);

        return $svc->syncStatus($sub);
    });

    expect($result->status)->toBe('past_due');
    expect($result->version)->toBe(2);
});

it('leaves a live trial untouched', function () use ($inTenant) {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $svc = new SubscriptionService();

    $result = $inTenant($business, $user->id, function () use ($svc, $business) {
        $sub = Subscription::create([
            'business_id' => $business->id, 'plan' => 'free',
            'status' => 'trialing', 'trial_ends_at' => now()->addDays(5),
        ]);

        return $svc->syncStatus($sub);
    });

    expect($result->status)->toBe('trialing');
    expect($result->version)->toBe(1);
});

it('activates a plan from a payment and stamps the verifier', function () use ($inTenant, $payment) {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $svc = new SubscriptionService();

    [$sub, $paid] = $inTenant($business, $user->id, function () use ($svc, $business, $payment) {
        Subscription::create([
            'business_id' => $business->id, 'plan' => 'free',
            'status' => 'trialing', 'trial_ends_at' => now()->addDays(14),
        ]);
        $p = $payment($business, 3);
        $sub = $svc->activateFromPayment($p);

        return [$sub, $p->fresh()];
    });

    expect($sub->status)->toBe('active');
    expect($sub->plan)->toBe('pro');
    expect($sub->current_period_end->timestamp)->toEqualWithDelta(now()->addMonths(3)->timestamp, 60);
    expect($paid->status)->toBe('verified');
    expect($paid->verified_by)->toBe($user->id);
    expect($paid->verified_at)->not->toBeNull();
});

it('does not extend again when a verified payment is replayed', function () use ($inTenant, $payment) {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $svc = new SubscriptionService();

    [$firstEnd, $secondEnd] = $inTenant($business, $user->id, function () use ($svc, $business, $payment) {
        Subscription::create([
            'business_id' => $business->id, 'plan' => 'free',
            'status' => 'trialing', 'trial_ends_at' => now()->addDays(14),
        ]);
        $p = $payment($business, 3);

        $svc->activateFromPayment($p);
        $first = Subscription::where('business_id', $business->id)->first()->current_period_end;

        $svc->activateFromPayment($p); // now verified — must not extend
        $second = Subscription::where('business_id', $business->id)->first()->current_period_end;

        return [$first, $second];
    });

    expect($secondEnd->timestamp)->toBe($firstEnd->timestamp);
});
