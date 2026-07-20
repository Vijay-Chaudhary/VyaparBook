<?php
// tests/Feature/HealthCheckTest.php

use App\Models\User;

/*
 * `/` stopped serving Laravel's welcome page when the Blade frontend landed:
 * it now routes a visitor to where they should actually be. The liveness probe
 * moved to /up (configured in bootstrap/app.php), which is the right place for
 * it — a health check should not depend on auth state.
 */

it('serves the health endpoint', function () {
    $this->get('/up')->assertStatus(200);
});

it('sends a guest at the root to login', function () {
    $this->get('/')->assertRedirect(route('app'));

    // /app then bounces a guest to the login page.
    $this->get('/app')->assertRedirect(route('login'));
});

it('sends a signed-in user at the root to the app', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('app'));
});
