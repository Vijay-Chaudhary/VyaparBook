<?php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;

it('lists every business the user belongs to', function () {
    $user = User::factory()->create();
    $businessA = Business::factory()->create(['name' => 'Shop A']);
    $businessB = Business::factory()->create(['name' => 'Shop B']);
    Membership::create(['user_id' => $user->id, 'business_id' => $businessA->id, 'role' => 'owner']);
    Membership::create(['user_id' => $user->id, 'business_id' => $businessB->id, 'role' => 'salesman']);

    $token = (new TokenService())->issue($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/businesses/mine')
        ->assertOk();

    expect($response->json())->toHaveCount(2);
});

it('switches to a business the user is a member of', function () {
    $user = User::factory()->create();
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();
    $membershipA = Membership::create(['user_id' => $user->id, 'business_id' => $businessA->id, 'role' => 'owner']);
    Membership::create(['user_id' => $user->id, 'business_id' => $businessB->id, 'role' => 'salesman']);

    $tokenForA = (new TokenService())->issue($user, $membershipA);

    $response = $this->withHeader('Authorization', "Bearer {$tokenForA}")
        ->postJson("/api/v1/businesses/{$businessB->id}/switch")
        ->assertOk();

    $payload = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::setToken($response->json('token'))->getPayload();
    expect($payload->get('tid'))->toBe($businessB->id);
    expect($payload->get('role'))->toBe('salesman');
});

it('rejects switching to a business the user is not a member of', function () {
    $user = User::factory()->create();
    $notMyBusiness = Business::factory()->create();
    $token = (new TokenService())->issue($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/businesses/{$notMyBusiness->id}/switch")
        ->assertStatus(403);
});

it('rejects switching into a business that someone else is a member of', function () {
    // The business above has no memberships at all, so that test passes even
    // with the authorization check deleted — nothing exists to find. Here a
    // membership row genuinely exists and belongs to a stranger, which is the
    // row a broken query would hand back.
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $strangersBusiness = Business::factory()->create();
    Membership::create([
        'user_id' => $stranger->id,
        'business_id' => $strangersBusiness->id,
        'role' => 'owner',
    ]);

    $token = (new TokenService())->issue($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/businesses/{$strangersBusiness->id}/switch")
        ->assertStatus(403);
});
