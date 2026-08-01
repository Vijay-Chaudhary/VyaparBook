<?php
// tests/Feature/Admin/PlatformConnectionTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the platform connection is still for.
 *
 * Its second assertion used to be "the app role sees nothing with no tenant
 * GUC set" — that was RLS, and it is gone. Reading is no longer the thing this
 * connection guarantees, because a raw builder on the app connection now reads
 * everything. What survives is the OTHER half of the old BYPASSRLS role: the
 * user is granted SELECT and nothing else, so the superadmin console cannot
 * mutate a tenant's data however wrong the application code gets. That is the
 * guarantee worth a test.
 */

function platformCustomer(): Business
{
    $business = Business::factory()->create();

    Tenancy::withoutTenant(fn () => Customer::create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Cross-Tenant Visible',
        'opening_balance' => '0.00',
    ]));

    return $business;
}

it('reads across tenants on the platform connection', function () {
    platformCustomer();

    expect(DB::connection('mysql_platform')->table('customers')->count())
        ->toBeGreaterThanOrEqual(1);
});

it('cannot write on the platform connection, whatever the code asks of it', function () {
    // The console is cross-tenant by design, so a mutation slipping onto this
    // connection would reach every shop at once. The grant is what stops it.
    $business = platformCustomer();

    expect(fn () => DB::connection('mysql_platform')
        ->table('customers')
        ->where('business_id', $business->id)
        ->update(['name' => 'Rewritten By The Console'])
    )->toThrow(Illuminate\Database\QueryException::class);
});
