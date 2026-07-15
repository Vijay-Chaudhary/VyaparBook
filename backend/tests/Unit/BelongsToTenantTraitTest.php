<?php
// tests/Unit/BelongsToTenantTraitTest.php

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;


class TenantFixtureItem extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_fixture_items';
    protected $guarded = [];
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
}

beforeEach(function () {
    Schema::connection('pgsql_migrate')->create('tenant_fixture_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('business_id');
        $table->string('name');
    });
});

afterEach(function () {
    Schema::connection('pgsql_migrate')->dropIfExists('tenant_fixture_items');
});

it('stamps business_id from the current tenant on create', function () {
    app()->instance('tenant.id', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

    $item = TenantFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);

    expect($item->business_id)->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
});

it('only returns rows for the current tenant', function () {
    app()->instance('tenant.id', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
    TenantFixtureItem::create(['id' => Str::uuid(), 'name' => 'Sev']);

    app()->instance('tenant.id', 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');
    TenantFixtureItem::create(['id' => Str::uuid(), 'name' => 'Mix']);

    expect(TenantFixtureItem::count())->toBe(1);
    expect(TenantFixtureItem::first()->name)->toBe('Mix');
});
