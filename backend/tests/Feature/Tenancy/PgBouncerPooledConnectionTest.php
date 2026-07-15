<?php
// tests/Feature/Tenancy/PgBouncerPooledConnectionTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\DB;

it('does not leak app.current_tenant across sequential requests on the same connection', function () {
    [$ownerA, $businessA, $tokenA] = (function () {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $membership = Membership::on('pgsql_migrate')->create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner',
        ]);
        return [$owner, $business, (new TokenService())->issue($owner, $membership)];
    })();

    [$ownerB, $businessB, $tokenB] = (function () {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $membership = Membership::on('pgsql_migrate')->create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'role' => 'owner',
        ]);
        return [$owner, $business, (new TokenService())->issue($owner, $membership)];
    })();

    // Request 1: tenant A. Middleware commits at the end of the request, which
    // is exactly the point PgBouncer would return the server connection to its pool.
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/whoami')
        ->assertOk()
        ->assertJson(['tenant_id' => $businessA->id]);

    // Immediately after commit, confirm the GUC has actually cleared on this
    // connection — proving SET LOCAL's scope ended with the transaction and did
    // not silently persist as session state (which is what would leak under PgBouncer).
    //
    // toBeEmpty(), not toBeNull(): once a custom GUC has been set in a session,
    // ending the transaction reverts it to '' rather than NULL. This is exactly
    // why every RLS policy wraps it as NULLIF(current_setting(...), '') — an
    // assertion of toBeNull() here fails against real Postgres.
    $leftover = DB::selectOne("select current_setting('app.current_tenant', true) as t")->t;
    expect($leftover)->toBeEmpty();

    // Request 2: tenant B, reusing the same underlying connection/process.
    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/v1/whoami')
        ->assertOk()
        ->assertJson(['tenant_id' => $businessB->id]);
});
