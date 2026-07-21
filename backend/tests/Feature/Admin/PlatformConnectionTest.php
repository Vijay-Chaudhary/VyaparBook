<?php
// tests/Feature/Admin/PlatformConnectionTest.php

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('reads across tenants on the bypass connection but not on the default one', function () {
    $business = Business::factory()->create();
    Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Cross-Tenant Visible',
        'opening_balance' => '0.00',
    ]);

    // BYPASSRLS role: sees the row with no tenant GUC set.
    expect(DB::connection('pgsql_platform')->table('customers')->count())->toBeGreaterThanOrEqual(1);

    // Default app role with no tenant set: RLS hides everything.
    expect(DB::connection('pgsql')->table('customers')->count())->toBe(0);
});
