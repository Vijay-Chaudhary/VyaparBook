<?php
// tests/Feature/Tenancy/CrossTenantLeakTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\Product;
use App\Models\User;
use App\Services\TokenService;

function ownerContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    return [$owner, $business, (new TokenService())->issue($owner, $membership)];
}

it('never returns business Bs memberships in business As mine listing', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/businesses/mine')
        ->assertOk();

    $businessIds = collect($response->json())->pluck('business.id');

    expect($businessIds)->toContain($businessA->id);
    expect($businessIds)->not->toContain($businessB->id);
});

it('rejects business As owner switching into business B', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/businesses/{$businessB->id}/switch")
        ->assertStatus(403);
});

it('rejects a token forged with another tenants tid without a matching membership', function () {
    [$ownerA, $businessA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    $forgedToken = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::claims([
        'tid' => $businessB->id,
        'role' => 'owner',
    ])->fromUser($ownerA);

    $this->withHeader('Authorization', "Bearer {$forgedToken}")
        ->getJson('/api/v1/whoami')
        ->assertStatus(403);
});

it('rejects business As owner inviting staff into business B via a mismatched path id', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();
    [$ownerB, $businessB] = ownerContext();

    // Owner A's token is scoped to business A; RequireTenant only confirms *a* tenant
    // is active, so the controller itself must not trust the {id} path segment blindly.
    // Since invite's business_id always comes from app('tenant.id'), not the path param,
    // this proves the invite is created for A, never for the B id in the URL.
    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/businesses/{$businessB->id}/invite", ['role' => 'salesman'])
        ->assertCreated();

    // latest('created_at'), not latest('id'): Invite uses HasUuids, so ordering
    // by id sorts UUIDs lexically rather than chronologically.
    $invite = \App\Models\Invite::latest('created_at')->first();
    expect($invite->business_id)->toBe($businessA->id);
    expect($invite->business_id)->not->toBe($businessB->id);
});

it('never lets accepting an expired invite create a membership', function () {
    [$ownerA, $businessA, $tokenA] = ownerContext();

    $invite = \App\Models\Invite::create([
        'business_id' => $businessA->id,
        'role' => 'salesman',
        'token' => 'expired-token-123',
        'invited_by' => $ownerA->id,
        'expires_at' => now()->subDay(),
    ]);

    $newUser = User::factory()->create();
    $newUserToken = (new TokenService())->issue($newUser);

    $this->withHeader('Authorization', "Bearer {$newUserToken}")
        ->postJson('/api/v1/invites/accept', ['token' => 'expired-token-123'])
        ->assertStatus(422);

    expect(Membership::on('pgsql_migrate')->where('user_id', $newUser->id)->exists())->toBeFalse();
});

it('never returns business Bs catalog to business A', function () {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $userA = User::factory()->create();
    $membershipA = Membership::on('pgsql_migrate')->create([
        'user_id' => $userA->id, 'business_id' => $businessA->id, 'role' => 'owner',
    ]);

    Product::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'name_hi' => 'हल्दी', 'name_en' => 'Haldi',
    ]);

    $token = (new TokenService())->issue($userA, $membershipA);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonCount(0, 'products');
});

it('rejects business As owner reading business Bs product by id', function () {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $userA = User::factory()->create();
    $membershipA = Membership::on('pgsql_migrate')->create([
        'user_id' => $userA->id, 'business_id' => $businessA->id, 'role' => 'owner',
    ]);

    $foreign = Product::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'name_hi' => 'हल्दी',
    ]);

    $token = (new TokenService())->issue($userA, $membershipA);

    // 404, not 403: a 403 would confirm the row exists, leaking that a
    // competitor's product id is real.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$foreign->id}", ['name_en' => 'Stolen'])
        ->assertStatus(404);
});

it('rejects business As owner archiving business Bs product', function () {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $userA = User::factory()->create();
    $membershipA = Membership::on('pgsql_migrate')->create([
        'user_id' => $userA->id, 'business_id' => $businessA->id, 'role' => 'owner',
    ]);

    $foreign = Product::on('pgsql_migrate')->create([
        'business_id' => $businessB->id, 'name_hi' => 'हल्दी',
    ]);

    $token = (new TokenService())->issue($userA, $membershipA);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$foreign->id}")
        ->assertStatus(404);

    // And it really is untouched. withoutGlobalScopes() because the request
    // above bound app('tenant.id') to business A for the rest of the process;
    // BelongsToTenant's scope would otherwise filter this business-B row out and
    // the read would see null — hiding, not proving, that the row is intact.
    expect(Product::on('pgsql_migrate')->withoutGlobalScopes()->find($foreign->id)->archived_at)->toBeNull();
});
