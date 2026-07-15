<?php

use App\Models\User;
use App\Services\TokenService;

it('creates a business and returns a token scoped to it as owner', function () {
    $user = User::factory()->create();
    $token = (new TokenService())->issue($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/businesses', ['name' => 'Shree Raj Shyama Ji Namkeen', 'city' => 'Hata'])
        ->assertCreated();

    $businessId = $response->json('business.id');
    expect($businessId)->not->toBeNull();

    $newToken = $response->json('token');
    $payload = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::setToken($newToken)->getPayload();
    expect($payload->get('tid'))->toBe($businessId);
    expect($payload->get('role'))->toBe('owner');
});

it('lets a user create a second business while already owning one', function () {
    $user = User::factory()->create();
    $firstToken = (new TokenService())->issue($user);

    $first = $this->withHeader('Authorization', "Bearer {$firstToken}")
        ->postJson('/api/v1/businesses', ['name' => 'First Shop'])
        ->assertCreated();

    // Authenticate using a token scoped to the FIRST business, then create a second.
    $tokenScopedToFirst = $first->json('token');

    $second = $this->withHeader('Authorization', "Bearer {$tokenScopedToFirst}")
        ->postJson('/api/v1/businesses', ['name' => 'Second Shop'])
        ->assertCreated();

    expect($second->json('business.id'))->not->toBe($first->json('business.id'));
});
