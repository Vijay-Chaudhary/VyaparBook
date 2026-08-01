<?php


it('registers a new user and issues a token', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Vijay Kumar',
        'email' => 'vijay@example.com',
        'password' => 'password123',
        'consent' => true,
    ])->assertCreated()->assertJsonStructure(['token']);
});

it('rejects login with the wrong password', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Vijay Kumar',
        'email' => 'vijay2@example.com',
        'password' => 'password123',
        'consent' => true,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'vijay2@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(401);
});

it('scopes the token to the business when the user has exactly one membership', function () {
    $user = \App\Models\User::factory()->create(['email' => 'solo@example.com']);
    $business = \App\Models\Business::factory()->create();
    \App\Models\Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'solo@example.com',
        'password' => 'password',
    ])->assertOk()->json('token');

    $payload = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::setToken($token)->getPayload();

    expect($payload->get('tid'))->toBe($business->id);
    expect($payload->get('role'))->toBe('owner');
});

it('logs in with the correct password', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Vijay Kumar',
        'email' => 'vijay3@example.com',
        'password' => 'password123',
        'consent' => true,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'vijay3@example.com',
        'password' => 'password123',
        'consent' => true,
    ])->assertOk()->assertJsonStructure(['token']);
});
