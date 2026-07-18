<?php
// tests/Feature/Tenancy/BillingRlsTest.php
//
// Proves the billing RLS policies themselves, with the app layer removed. Uses
// the query builder rather than Eloquent so BelongsToTenant's global scope
// cannot mask whether RLS is doing the work. Mirrors StockRlsTest.

use App\Models\Business;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Seed a subscription + payment for one business on the migrate connection (bypasses RLS). */
function seedBilling(Business $business): void
{
    Subscription::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'plan' => 'pro', 'status' => 'active',
        'trial_ends_at' => now()->subDays(14), 'current_period_end' => now()->addMonth(),
    ]);
    SubscriptionPayment::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'plan' => 'pro', 'amount' => '499.00', 'gst_amount' => '89.82',
        'mode' => 'upi', 'period_months' => 1, 'status' => 'pending',
    ]);
}

it('hides another business billing rows even with the app layer bypassed', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    seedBilling($theirs);

    DB::transaction(function () use ($mine) {
        TenantContext::switchTo($mine->id);

        expect(DB::table('subscriptions')->count())->toBe(0);
        expect(DB::table('subscription_payments')->count())->toBe(0);
    });
});

it('blocks inserting a subscription for another tenant', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    expect(function () use ($mine, $theirs) {
        DB::transaction(function () use ($mine, $theirs) {
            TenantContext::switchTo($mine->id);

            DB::table('subscriptions')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $theirs->id, // mismatched on purpose
                'plan' => 'pro',
                'status' => 'active',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});

it('blocks inserting a subscription payment for another tenant', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    expect(function () use ($mine, $theirs) {
        DB::transaction(function () use ($mine, $theirs) {
            TenantContext::switchTo($mine->id);

            DB::table('subscription_payments')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $theirs->id, // mismatched on purpose
                'uuid' => (string) Str::uuid(),
                'plan' => 'pro',
                'amount' => '499.00',
                'gst_amount' => '89.82',
                'mode' => 'upi',
                'period_months' => 1,
                'status' => 'pending',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});

it('shows a business its own billing rows', function () {
    $mine = Business::factory()->create();
    seedBilling($mine);

    DB::transaction(function () use ($mine) {
        TenantContext::switchTo($mine->id);

        expect(DB::table('subscriptions')->count())->toBe(1);
        expect(DB::table('subscription_payments')->count())->toBe(1);
    });
});
