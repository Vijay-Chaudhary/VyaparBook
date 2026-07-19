<?php
// tests/Feature/Admin/TenantListingTest.php

it('lists every tenant across the platform with its billing state', function () {
    [$active] = seedTenantWithOwner('active', 'pro');
    [$trialing] = seedTenantWithOwner('trialing', 'free');

    $token = platformAdminToken();

    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/tenants')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    // Cross-tenant: the console sees both tenants without any tenant GUC.
    $ids = collect($res->json('data'))->pluck('id');
    expect($ids)->toContain($active->id, $trialing->id);

    // Each row carries the joined subscription state.
    $activeRow = collect($res->json('data'))->firstWhere('id', $active->id);
    expect($activeRow['subscription']['status'])->toBe('active')
        ->and($activeRow['subscription']['plan'])->toBe('pro');
});

it('reports a null subscription for a tenant that has none', function () {
    $business = \App\Models\Business::factory()->create();

    $token = platformAdminToken();

    $row = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/tenants')
        ->assertOk()
        ->json('data.0');

    expect($row['id'])->toBe($business->id)
        ->and($row['subscription'])->toBeNull();
});

it('filters by name with a case-insensitive search', function () {
    seedTenantWithOwner();
    $named = \App\Models\Business::factory()->create(['name' => 'Sharma Distributors']);

    $token = platformAdminToken();

    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/tenants?q=sharma')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    expect($res->json('data.0.id'))->toBe($named->id);
});

it('caps and honours the per_page parameter', function () {
    foreach (range(1, 3) as $i) {
        \App\Models\Business::factory()->create();
    }

    $token = platformAdminToken();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/tenants?per_page=2')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonCount(2, 'data');
});
