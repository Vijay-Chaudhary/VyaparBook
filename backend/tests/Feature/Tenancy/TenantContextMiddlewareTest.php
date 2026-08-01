<?php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;


it('resolves tenant id and role from the token for a member', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);
    $token = (new TokenService())->issue($user, $membership);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/whoami')
        ->assertOk()
        ->assertJson([
            'user_id' => $user->id,
            'tenant_id' => $business->id,
            'role' => 'owner',
        ]);
});

it('resolves a tenant-less token without error', function () {
    $user = User::factory()->create();
    $token = (new TokenService())->issue($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/whoami')
        ->assertOk()
        ->assertJson([
            'user_id' => $user->id,
            'tenant_id' => null,
            'role' => null,
        ]);
});

it('rejects a token whose tid the user is not a member of', function () {
    $user = User::factory()->create();
    $otherBusiness = Business::factory()->create();

    $rawToken = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::claims([
        'tid' => $otherBusiness->id,
        'role' => 'owner',
    ])->fromUser($user);

    $this->withHeader('Authorization', "Bearer {$rawToken}")
        ->getJson('/api/v1/whoami')
        ->assertStatus(403);
});
