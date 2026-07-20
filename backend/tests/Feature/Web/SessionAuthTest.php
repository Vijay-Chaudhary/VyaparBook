<?php
// tests/Feature/Web/SessionAuthTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/*
 * The Blade layer's session auth and the session -> JWT bridge
 * (docs/frontend-plan.md §2). The dual-auth model only earns its complexity
 * if both halves genuinely agree, so these tests assert the handoff end to
 * end rather than each half in isolation.
 */

it('shows the login page to a guest', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('साइन इन करें'); // Hindi is the default locale (PRD §16)
});

it('signs a user in and starts a session', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->post('/login', ['email' => 'owner@example.com', 'password' => 'password'])
        ->assertRedirect(route('app'));

    $this->assertAuthenticatedAs($user);
});

it('rejects bad credentials without revealing whether the account exists', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $wrongPassword = $this->post('/login', [
        'email' => 'owner@example.com',
        'password' => 'not-the-password',
    ]);

    $unknownEmail = $this->post('/login', [
        'email' => 'nobody@example.com',
        'password' => 'password',
    ]);

    // Identical message either way: a different one would let an attacker
    // enumerate which emails have accounts.
    $wrongPassword->assertSessionHasErrors('email');
    $unknownEmail->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toBe(__('auth.failed'));

    $this->assertGuest();
});

it('regenerates the session id on login', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => 'owner@example.com', 'password' => 'password']);

    // A session fixed before login must not survive it.
    expect(session()->getId())->not->toBe($before);
});

it('signs the user out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

    $this->assertGuest();
});

it('keeps the app shell behind auth', function () {
    $this->get('/app')->assertRedirect(route('login'));
});

it('serves one shell for every client-side route', function () {
    $user = User::factory()->create();

    // The service worker caches ONE document; deep links must all resolve to it.
    foreach (['/app', '/app/khata', '/app/sales/new'] as $path) {
        $this->actingAs($user)->get($path)->assertOk()->assertSee('app-root', false);
    }
});

/*
 * The bridge itself.
 */

it('refuses to mint a token without a session', function () {
    $this->getJson('/auth/token')->assertStatus(401);
});

it('mints a JWT the API actually accepts', function () {
    $user = User::factory()->create();

    $token = $this->actingAs($user)->getJson('/auth/token')
        ->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'expires_in_minutes'])
        ->json('token');

    // The point of the whole dual-auth design: a session-minted token must be
    // a first-class API credential, not a special case.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/whoami')
        ->assertOk()
        ->assertJsonPath('user_id', $user->id);
});

it('scopes the token to the tenant when the user has exactly one membership', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    $response = $this->actingAs($user)->getJson('/auth/token')
        ->assertOk()
        ->assertJsonPath('tenant_id', $business->id)
        ->assertJsonPath('role', 'owner');

    $payload = JWTAuth::setToken($response->json('token'))->getPayload();
    expect($payload->get('tid'))->toBe($business->id)
        ->and($payload->get('role'))->toBe('owner');
});

it('mints a tenant-less token when the user belongs to several businesses', function () {
    $user = User::factory()->create();

    foreach (range(1, 2) as $_) {
        Membership::on('pgsql_migrate')->create([
            'user_id' => $user->id,
            'business_id' => Business::factory()->create()->id,
            'role' => 'owner',
        ]);
    }

    // Ambiguous, so the client must choose via /businesses/mine + switch.
    $this->actingAs($user)->getJson('/auth/token')
        ->assertOk()
        ->assertJsonPath('tenant_id', null)
        ->assertJsonPath('role', null);
});

it('throttles the token endpoint even with a valid session', function () {
    $user = User::factory()->create();

    // It mints credentials, so a session alone must not make it spammable.
    foreach (range(1, 6) as $_) {
        $this->actingAs($user)->getJson('/auth/token')->assertOk();
    }

    $this->actingAs($user)->getJson('/auth/token')->assertStatus(429);
});

it('throttles Blade login by the same limiter as the API', function () {
    config(['ratelimits.login' => 2]);

    $attempt = fn () => $this->post('/login', [
        'email' => 'victim@example.com',
        'password' => 'wrong',
    ]);

    $attempt();
    $attempt();

    // The Blade form must not be a softer door onto the same credentials.
    $attempt()->assertStatus(429);
});
