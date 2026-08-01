<?php
// tests/Feature/Export/TenantExportTest.php

use App\Export\TenantExporter;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed a tenant with an owner and $n customers, returning the business.
 * Customers are written through the importer's tenant-context pattern so RLS
 * accepts the inserts.
 */
function seedTenantWithCustomers(array $names): Business
{
    $business = Business::factory()->create();

    Membership::create([
        'user_id' => User::factory()->create()->id,
        'business_id' => $business->id,
        'role' => 'owner',
    ]);

    foreach ($names as $name) {
        Customer::create([
            'business_id' => $business->id,
            'uuid' => (string) Str::uuid(),
            'name' => $name,
        ]);
    }

    return $business;
}

it('exports the tenant\'s rows with a manifest and counts', function () {
    $business = seedTenantWithCustomers(['Ram Traders', 'Shyam Stores']);

    $export = (new TenantExporter())->export($business->id);

    expect($export['manifest']['format_version'])->toBe(TenantExporter::FORMAT_VERSION)
        ->and($export['manifest']['business']['id'])->toBe($business->id)
        ->and($export['manifest']['counts']['customers'])->toBe(2);

    $names = array_column($export['data']['customers'], 'name');
    sort($names);
    expect($names)->toBe(['Ram Traders', 'Shyam Stores']);
});

/**
 * The property that matters: the export reads under the tenant's own RLS
 * context, so it is confined by the same policy as the tenant's own requests.
 * A leak here would hand one shop's books to another during offboarding.
 */
it('never includes another tenant\'s rows', function () {
    $mine = seedTenantWithCustomers(['Mine Only']);
    seedTenantWithCustomers(['Neighbour Tenant']);

    $export = (new TenantExporter())->export($mine->id);

    $names = array_column($export['data']['customers'], 'name');
    expect($names)->toBe(['Mine Only'])
        ->and($names)->not->toContain('Neighbour Tenant');
    expect($export['manifest']['counts']['customers'])->toBe(1);
});

it('includes the staff list with roles and identities', function () {
    $business = seedTenantWithCustomers([]);

    $export = (new TenantExporter())->export($business->id);

    expect($export['data']['memberships'])->toHaveCount(1);
    expect($export['data']['memberships'][0]['role'])->toBe('owner');
    expect($export['data']['memberships'][0])->toHaveKeys(['user_id', 'name', 'phone']);
});

it('covers every tenant-owned table so nothing is silently omitted', function () {
    $business = seedTenantWithCustomers([]);

    $export = (new TenantExporter())->export($business->id);

    // Derived from the live schema, NOT from a list copied out of the exporter —
    // otherwise this asserts the exporter equals itself and a new tenant table
    // would slip through. Any table carrying business_id is tenant-owned and
    // must appear in a portability export; a silently incomplete one is a
    // compliance failure that looks like a success.
    $tenantTables = collect(DB::select(
        'select table_name from information_schema.columns
         where column_name = ? and table_schema = ?',
        ['business_id', 'public']
    ))->pluck('table_name')->all();

    expect($tenantTables)->not->toBeEmpty();
    expect(array_keys($export['data']))->toEqualCanonicalizing($tenantTables);
});

it('throws rather than emitting an empty export for an unknown tenant', function () {
    (new TenantExporter())->export((string) Str::uuid());
})->throws(RuntimeException::class);

it('writes a readable JSON file and audits the export', function () {
    $business = seedTenantWithCustomers(['Ram Traders']);
    $path = sys_get_temp_dir().'/export-test-'.Str::random(8).'.json';

    $this->artisan('tenant:export', ['business_id' => $business->id, '--output' => $path])
        ->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();

    $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded['data']['customers'][0]['name'])->toBe('Ram Traders');

    $log = PlatformAuditLog::where('action', 'export_tenant')->first();
    expect($log)->not->toBeNull();
    // CLI has no logged-in console admin — a null actor here is deliberate.
    expect($log->admin_user_id)->toBeNull()
        ->and($log->target_business_id)->toBe($business->id)
        ->and($log->metadata['via'])->toBe('cli');

    unlink($path);
});

it('keeps Devanagari readable rather than escaping it', function () {
    $business = seedTenantWithCustomers(['राम ट्रेडर्स']);
    $path = sys_get_temp_dir().'/export-hindi-'.Str::random(8).'.json';

    $this->artisan('tenant:export', ['business_id' => $business->id, '--output' => $path])
        ->assertExitCode(0);

    // A Hindi-first tenant should be able to open their own export and
    // recognise it, not find राम.
    expect(file_get_contents($path))->toContain('राम ट्रेडर्स');

    unlink($path);
});

it('exits 1 for an unknown business', function () {
    $this->artisan('tenant:export', ['business_id' => (string) Str::uuid()])
        ->assertExitCode(1);
});
