<?php
// tests/Feature/Web/BillingPageTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * The Blade billing & plan page (docs/frontend-plan.md §7 Phase 6):
 * session-authorised, owner-only, online-only.
 *
 * Like onboarding, it runs on the web guard (a user but no tenant) and pins the
 * tenant itself — so these tests double as a check that a non-JWT surface reads
 * and writes the right tenant's subscription, and no one else's.
 */

/**
 * A session owner with a business, subscription and owner membership.
 *
 * @return array{0: User, 1: Business}
 */
function webBillingOwner(string $status = 'trialing', string $plan = 'free'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();

    Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    Subscription::create([
        'business_id' => $business->id,
        'plan' => $plan,
        'status' => $status,
        'trial_ends_at' => $status === 'trialing' ? now()->addDays(14) : now()->subDay(),
        'current_period_end' => in_array($status, ['active', 'read_only'], true) ? now()->addMonth() : null,
    ]);

    return [$user, $business];
}

describe('access', function () {
    it('is reached only when signed in', function () {
        $this->get('/billing')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        // A signed-in user with no owner membership has no billing to manage.
        $user = User::factory()->create();

        $this->actingAs($user)->get('/billing')->assertRedirect(route('app'));
    });

    it('refuses an owner asking for a business they do not own', function () {
        [$owner] = webBillingOwner();
        [, $other] = webBillingOwner();

        // Owns SOME business, but not this one → resolves to nothing → app.
        $this->actingAs($owner)
            ->get('/billing?business=' . $other->id)
            ->assertRedirect(route('app'));
    });
});

describe('plan display', function () {
    it('shows a trialing owner their Pro trial and usage', function () {
        [$owner] = webBillingOwner('trialing');

        $this->actingAs($owner)
            ->get('/billing')
            ->assertOk()
            ->assertSee(__('billing.heading'))
            ->assertSee(__('billing.plan_pro'))          // trial entitles Pro
            ->assertSee(__('billing.usage'))
            ->assertSee(__('billing.record_payment'));
    });

    it('shows the dunning banner and still lets a read_only owner in', function () {
        [$owner] = webBillingOwner('read_only');

        $this->actingAs($owner)
            ->get('/billing')
            ->assertOk()
            // The page is the way OUT of read_only, never blocked by it.
            ->assertSee(__('billing.read_only_banner'))
            ->assertSee(__('billing.record_payment'));
    });

    it('floors an expired trial to Free with the past-due banner', function () {
        // status 'past_due' models a lapsed trial/period; effectivePlan → free.
        [$owner] = webBillingOwner('past_due');

        $this->actingAs($owner)
            ->get('/billing')
            ->assertOk()
            ->assertSee(__('billing.plan_free'))
            ->assertSee(__('billing.past_due_banner'));
    });
});

describe('record payment', function () {
    it('records a pending payment and confirms it', function () {
        [$owner, $business] = webBillingOwner('past_due');

        $this->actingAs($owner)
            ->post('/billing/payment', [
                'business' => $business->id,
                'plan' => 'pro',
                'amount' => '499.00',
                'mode' => 'upi',
                'period_months' => 12,
                'reference' => 'UPI-123',
            ])
            ->assertRedirect(route('billing', ['business' => $business->id]))
            ->assertSessionHas('billing_status', 'payment_recorded');

        $payment = SubscriptionPayment::where('business_id', $business->id)->first();

        expect($payment)->not->toBeNull();
        expect($payment->status)->toBe('pending');       // never auto-activated
        expect($payment->plan)->toBe('pro');
        // 18% GST, at scale 2 — not a float.
        expect((string) $payment->gst_amount)->toBe('89.82');

        // The subscription is untouched: activation is the platform's job.
        expect(Subscription::where('business_id', $business->id)->value('status'))
            ->toBe('past_due');
    });

    it('is idempotent on a replayed uuid', function () {
        [$owner, $business] = webBillingOwner();
        $uuid = (string) Str::uuid();

        $body = [
            'business' => $business->id, 'uuid' => $uuid, 'plan' => 'pro',
            'amount' => '499.00', 'mode' => 'upi', 'period_months' => 1,
        ];

        $this->actingAs($owner)->post('/billing/payment', $body);
        $this->actingAs($owner)->post('/billing/payment', $body); // double submit

        expect(SubscriptionPayment::where('uuid', $uuid)->count())->toBe(1);
    });

    it('validates the amount and plan', function () {
        [$owner, $business] = webBillingOwner();

        $this->actingAs($owner)
            ->post('/billing/payment', [
                'business' => $business->id,
                'plan' => 'free',      // only 'pro' is purchasable
                'amount' => '0',       // must be > 0
                'mode' => 'upi',
                'period_months' => 1,
            ])
            ->assertSessionHasErrors(['plan', 'amount']);

        // Rejected before the tenant middleware bound anything, so name the shop.
        expect(asTenant($business->id, fn () => SubscriptionPayment::exists()))
            ->toBeFalse();
    });

    it('refuses to record against a business the caller does not own', function () {
        [$owner] = webBillingOwner();
        [, $other] = webBillingOwner();

        $this->actingAs($owner)
            ->post('/billing/payment', [
                'business' => $other->id,
                'plan' => 'pro', 'amount' => '499.00', 'mode' => 'upi', 'period_months' => 1,
            ])
            ->assertRedirect(route('app'));

        // The caller was bounced to /app, so nothing is bound; check the shop
        // they tried to write to.
        expect(asTenant($other->id, fn () => SubscriptionPayment::exists()))
            ->toBeFalse();
    });
});
