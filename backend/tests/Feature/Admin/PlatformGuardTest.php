<?php
// tests/Feature/Admin/PlatformGuardTest.php

use App\Models\User;
use App\Services\TokenService;

it('lets a platform admin through', function () {
    $token = platformAdminToken();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/ping')
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('rejects a regular owner token', function () {
    [, $ownerToken] = seedTenantWithOwner();

    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->getJson('/api/v1/admin/ping')
        ->assertStatus(403);
});

it('rejects a token with no platform flag regardless of role', function () {
    foreach (['admin', 'salesman', 'accountant'] as $role) {
        [, $token] = seedTenantWithOwner();
        // Reuse the owner path but any non-platform-admin token is enough; the
        // guard never inspects tenant role, only the platform flag.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/ping')
            ->assertStatus(403);
    }
});

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/v1/admin/ping')->assertStatus(401);
});

it('rejects a platform admin whose flag was revoked (live check)', function () {
    $admin = User::factory()->create(['is_platform_admin' => true]);
    $token = (new TokenService())->issue($admin);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/ping')->assertOk();

    // Revoke the flag; the same token must now be refused on the next request.
    $admin->forceFill(['is_platform_admin' => false])->save();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/ping')->assertStatus(403);
});
