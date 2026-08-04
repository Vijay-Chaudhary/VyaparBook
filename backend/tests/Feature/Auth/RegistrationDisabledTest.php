<?php

// Signup is closed by config('auth.registration_enabled'). The flag has to shut
// BOTH surfaces: the Blade form and the API are two doors onto the same flow,
// and a stranger through either one lands in onboarding and creates a tenant.

it('serves the register page and the signup link while registration is enabled', function () {
    config(['auth.registration_enabled' => true]);

    $this->get('/register')->assertOk();
    $this->get('/login')->assertOk()->assertSee('/register', escape: false);
});

it('404s the Blade register page when registration is disabled', function () {
    config(['auth.registration_enabled' => false]);

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Stranger',
        'email' => 'stranger@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'consent' => true,
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
});

it('404s the API register endpoint when registration is disabled', function () {
    config(['auth.registration_enabled' => false]);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Stranger',
        'email' => 'api-stranger@example.com',
        'password' => 'password123',
        'consent' => true,
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => 'api-stranger@example.com']);
});

it('hides the signup link on the login page when registration is disabled', function () {
    config(['auth.registration_enabled' => false]);

    // Sign-IN must keep working: the login page still renders even though
    // route('register') is gated, which is why the route stays registered.
    $this->get('/login')->assertOk()->assertDontSee('/register', escape: false);
});
