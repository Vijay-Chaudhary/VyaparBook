<?php

use App\Models\Business;
use App\Models\Invite;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;

it('lets an owner create an invite', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $membership = Membership::create(['user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner']);
    $token = (new TokenService())->issue($owner, $membership);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/businesses/{$business->id}/invite", ['role' => 'salesman'])
        ->assertCreated()
        ->assertJsonStructure(['invite_link']);
});

it('blocks a salesman from creating an invite', function () {
    $salesman = User::factory()->create();
    $business = Business::factory()->create();
    $membership = Membership::create(['user_id' => $salesman->id, 'business_id' => $business->id, 'role' => 'salesman']);
    $token = (new TokenService())->issue($salesman, $membership);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/businesses/{$business->id}/invite", ['role' => 'salesman'])
        ->assertStatus(403);
});

it('rejects accepting an invite for a business the user already belongs to', function () {
    // Invite links get shared in group chats, so an existing member tapping one
    // is routine. Without a guard the memberships unique index raises and the
    // request 500s with a raw SQL error.
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $ownerMembership = Membership::create(['user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner']);
    $ownerToken = (new TokenService())->issue($owner, $ownerMembership);

    $inviteResponse = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson("/api/v1/businesses/{$business->id}/invite", ['role' => 'salesman'])
        ->assertCreated();

    $inviteToken = str($inviteResponse->json('invite_link'))->after('token=')->toString();

    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/invites/accept', ['token' => $inviteToken])
        ->assertStatus(409);

    // The invite must survive unredeemed so the person it was meant for can use it.
    expect(Invite::where('token', $inviteToken)->first()->redeemed_at)->toBeNull();
});

it('lets a new user accept an invite and become a member', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $ownerMembership = Membership::create(['user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner']);
    $ownerToken = (new TokenService())->issue($owner, $ownerMembership);

    $inviteResponse = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson("/api/v1/businesses/{$business->id}/invite", ['role' => 'salesman'])
        ->assertCreated();

    $inviteToken = str($inviteResponse->json('invite_link'))->after('token=')->toString();

    $newUser = User::factory()->create();
    $newUserToken = (new TokenService())->issue($newUser);

    $acceptResponse = $this->withHeader('Authorization', "Bearer {$newUserToken}")
        ->postJson('/api/v1/invites/accept', ['token' => $inviteToken])
        ->assertOk();

    $payload = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::setToken($acceptResponse->json('token'))->getPayload();
    expect($payload->get('tid'))->toBe($business->id);
    expect($payload->get('role'))->toBe('salesman');
});

it('marks an invite redeemed so a second person cannot reuse the link', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $ownerMembership = Membership::create(['user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner']);
    $ownerToken = (new TokenService())->issue($owner, $ownerMembership);

    $inviteResponse = $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson("/api/v1/businesses/{$business->id}/invite", ['role' => 'salesman'])
        ->assertCreated();

    $inviteToken = str($inviteResponse->json('invite_link'))->after('token=')->toString();

    $firstUser = User::factory()->create();
    $this->withHeader('Authorization', 'Bearer ' . (new TokenService())->issue($firstUser))
        ->postJson('/api/v1/invites/accept', ['token' => $inviteToken])
        ->assertOk();

    $invite = Invite::where('token', $inviteToken)->first();
    expect($invite->redeemed_at)->not->toBeNull();
    expect($invite->redeemed_by)->toBe($firstUser->id);

    // An invite is single-use. A second person holding the same link — forwarded
    // from a group chat, say — must not be able to join the business with it.
    $secondUser = User::factory()->create();
    $this->withHeader('Authorization', 'Bearer ' . (new TokenService())->issue($secondUser))
        ->postJson('/api/v1/invites/accept', ['token' => $inviteToken])
        ->assertStatus(422);

    expect(Membership::where('user_id', $secondUser->id)->exists())->toBeFalse();
});
