<?php
// tests/Feature/Web/OnboardingTest.php

use App\Models\Business;
use App\Models\Consent;
use App\Models\Invite;
use App\Models\Membership;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;

/*
 * The Blade onboarding chain (docs/frontend-plan.md §7 Phase 4):
 * signup + consent → create business → choose template → invite staff.
 *
 * Session-authorised, online-only. The steps after business creation touch
 * tenant-scoped tables, so these tests double as a check that the controller
 * pins tenant context correctly on a surface that has no JWT middleware.
 */

/** Register through the Blade form and return the created user. */
function registerViaWeb(array $overrides = []): User
{
    test()->post('/register', array_merge([
        'name' => 'Vijay Kumar',
        'email' => 'owner-'.uniqid().'@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'consent' => '1',
    ], $overrides));

    return User::latest('id')->first();
}

describe('signup', function () {
    it('creates an account, records consent, and lands on create-business', function () {
        $response = $this->post('/register', [
            'name' => 'Vijay Kumar',
            'email' => 'vijay@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'consent' => '1',
        ]);

        $response->assertRedirect(route('onboarding.business'));
        $this->assertAuthenticated();

        $user = User::where('email', 'vijay@example.com')->first();
        expect($user)->not->toBeNull();

        // Consent is recorded on this surface too — the web door must not be a
        // way to create an account without it.
        $consent = Consent::where('user_id', $user->id)->first();
        expect($consent?->action)->toBe(Consent::GRANTED);
    });

    it('refuses signup without consent, creating nothing', function () {
        $this->post('/register', [
            'name' => 'No Consent',
            'email' => 'noconsent@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('consent');

        expect(User::where('email', 'noconsent@example.com')->exists())->toBeFalse();
        $this->assertGuest();
    });

    it('requires the password to be confirmed', function () {
        $this->post('/register', [
            'name' => 'Mismatch',
            'email' => 'mismatch@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different',
            'consent' => '1',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    });
});

describe('create business', function () {
    it('provisions the business, owner membership and trial, then goes to templates', function () {
        $user = registerViaWeb();

        $this->actingAs($user)
            ->post('/onboarding/business', ['name' => 'Shree Raj Shyama Ji', 'city' => 'Jaipur'])
            ->assertRedirect(route('onboarding.template'));

        $business = Business::where('name', 'Shree Raj Shyama Ji')->first();
        expect($business)->not->toBeNull();

        // Owner membership …
        expect(
            Membership::where('user_id', $user->id)->where('business_id', $business->id)->where('role', 'owner')->exists()
        )->toBeTrue();

        // … and a trial subscription, same as the API path (shared provisioner).
        expect(Subscription::where('business_id', $business->id)->first()?->status)
            ->toBe('trialing');
    });

    it('is reached only when signed in', function () {
        $this->get('/onboarding/business')->assertRedirect(route('login'));
    });

    it('sends an already-onboarded owner past step 1', function () {
        $user = registerViaWeb();
        $this->actingAs($user)->post('/onboarding/business', ['name' => 'First Shop']);

        // Re-visiting create-business must not offer a second first shop.
        $this->actingAs($user)->get('/onboarding/business')->assertRedirect(route('onboarding.template'));
    });
});

describe('choose template', function () {
    function ownerWithBusiness(): User
    {
        $user = registerViaWeb();
        test()->actingAs($user)->post('/onboarding/business', ['name' => 'Namkeen Shop']);

        return $user;
    }

    it('seeds the chosen template into the tenant', function () {
        $user = ownerWithBusiness();

        $this->actingAs($user)
            ->post('/onboarding/template', ['template' => 'namkeen'])
            ->assertRedirect(route('onboarding.invite'));

        $business = Membership::where('user_id', $user->id)->value('business_id');

        // Products landed in THIS tenant.
        expect(Product::where('business_id', $business)->count())->toBeGreaterThan(0);
    });

    it('accepts the blank template without seeding anything', function () {
        $user = ownerWithBusiness();

        $this->actingAs($user)
            ->post('/onboarding/template', ['template' => 'blank'])
            ->assertRedirect(route('onboarding.invite'));

        $business = Membership::where('user_id', $user->id)->value('business_id');
        expect(Product::where('business_id', $business)->count())->toBe(0);
    });

    it('rejects an unknown template', function () {
        $user = ownerWithBusiness();

        $this->actingAs($user)
            ->post('/onboarding/template', ['template' => 'not-a-template'])
            ->assertSessionHasErrors('template');
    });

    it('does not seed twice on a double submit', function () {
        $user = ownerWithBusiness();

        $this->actingAs($user)->post('/onboarding/template', ['template' => 'namkeen']);
        // A second submit must be a no-op, not a duplicate-key 500.
        $this->actingAs($user)->post('/onboarding/template', ['template' => 'namkeen'])
            ->assertRedirect(route('onboarding.invite'));

        $business = Membership::where('user_id', $user->id)->value('business_id');
        $count = Product::where('business_id', $business)->count();

        // Seeding once produced N products; the replay added none.
        $fresh = registerViaWeb();
        $this->actingAs($fresh)->post('/onboarding/business', ['name' => 'Baseline']);
        $this->actingAs($fresh)->post('/onboarding/template', ['template' => 'namkeen']);
        $baseline = Membership::where('user_id', $fresh->id)->value('business_id');

        expect($count)->toBe(Product::where('business_id', $baseline)->count());
    });
});

describe('invite staff', function () {
    function ownerReadyToInvite(): User
    {
        $user = registerViaWeb();
        test()->actingAs($user)->post('/onboarding/business', ['name' => 'Invite Shop']);

        return $user;
    }

    it('creates an invite scoped to the owner\'s business and shows a link', function () {
        $user = ownerReadyToInvite();

        $this->actingAs($user)
            ->post('/onboarding/invite', ['role' => 'salesman'])
            ->assertRedirect(route('onboarding.invite'))
            ->assertSessionHas('invite_link');

        $business = Membership::where('user_id', $user->id)->value('business_id');

        $invite = Invite::where('business_id', $business)->first();
        expect($invite)->not->toBeNull();
        expect($invite->role)->toBe('salesman')
            ->and($invite->invited_by)->toBe($user->id);
    });

    it('rejects an invalid role', function () {
        $user = ownerReadyToInvite();

        $this->actingAs($user)
            ->post('/onboarding/invite', ['role' => 'owner']) // owners are not invited
            ->assertSessionHasErrors('role');
    });

    it('can be skipped straight into the app', function () {
        $user = ownerReadyToInvite();

        // The finish/skip link is a plain GET to the app shell.
        $this->actingAs($user)->get(route('app'))->assertOk();
    });
});
