<?php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;


it('issues a token with no tid when no membership is given', function () {
    $user = User::factory()->create();

    $token = (new TokenService())->issue($user);
    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('tid'))->toBeNull();
    expect((int) $payload->get('sub'))->toBe($user->id);
});

it('issues a token with tid and role when a membership is given', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    $token = (new TokenService())->issue($user, $membership);
    $payload = JWTAuth::setToken($token)->getPayload();

    expect($payload->get('tid'))->toBe($business->id);
    expect($payload->get('role'))->toBe('owner');
});
