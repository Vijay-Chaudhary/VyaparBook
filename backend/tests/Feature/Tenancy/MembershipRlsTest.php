<?php
// tests/Feature/Tenancy/MembershipRlsTest.php

use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('lets a user see their own membership without a tenant set', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    DB::transaction(function () use ($user) {
        // set_config(name, value, true) is the parameterizable equivalent of
        // `SET LOCAL app.current_user_id = ?` — Postgres's SET statement grammar
        // rejects bind parameters in the value position, so `?`/`$1` here would
        // be a syntax error rather than a GUC assignment. See TenantContext::switchTo().
        DB::statement("SELECT set_config('app.current_user_id', ?, true)", [$user->id]);

        $visible = Membership::where('user_id', $user->id)->count();

        expect($visible)->toBe(1);
    });
});

it('blocks inserting a membership for a business other than the current tenant', function () {
    $user = User::factory()->create();
    $business = Business::factory()->create();
    $otherBusiness = Business::factory()->create();

    expect(function () use ($user, $business, $otherBusiness) {
        DB::transaction(function () use ($user, $business, $otherBusiness) {
            DB::statement("SELECT set_config('app.current_user_id', ?, true)", [$user->id]);
            DB::statement("SELECT set_config('app.current_tenant', ?, true)", [$business->id]);

            Membership::create([
                'user_id' => $user->id,
                'business_id' => $otherBusiness->id, // mismatched on purpose
                'role' => 'owner',
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});
