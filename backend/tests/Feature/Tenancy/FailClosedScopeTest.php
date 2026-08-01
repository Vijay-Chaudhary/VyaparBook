<?php
// tests/Feature/Tenancy/FailClosedScopeTest.php

use App\Exceptions\TenantContextMissing;
use App\Models\Business;
use App\Models\Customer;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function fcsCustomer(Business $b, string $name): Customer
{
    return Customer::create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'opening_balance' => '0.00',
    ]);
}

it('throws rather than returning every tenant when no tenant is bound', function () {
    // This is the whole migration in one test. Under Postgres an unscoped query
    // was caught by RLS underneath. There is nothing underneath now, so the
    // scope must refuse instead of quietly returning the entire platform.
    $a = Business::factory()->create();
    fcsCustomer($a, 'Ram');

    app()->bind('tenant.id', fn () => null);

    expect(fn () => Customer::query()->get())->toThrow(TenantContextMissing::class);
});

it('scopes normally when a tenant is bound', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();
    fcsCustomer($a, 'Mine');
    fcsCustomer($b, 'Theirs');

    app()->bind('tenant.id', fn () => $a->id);

    expect(Customer::query()->pluck('name')->all())->toBe(['Mine']);
});

it('lets an explicit withoutTenant block read across tenants', function () {
    // The four legitimate cross-tenant paths: seeders, the platform console,
    // auth before tenant selection, and the inbound WhatsApp STOP write.
    $a = Business::factory()->create();
    $b = Business::factory()->create();
    fcsCustomer($a, 'Mine');
    fcsCustomer($b, 'Theirs');

    app()->bind('tenant.id', fn () => null);

    $names = Tenancy::withoutTenant(fn () => Customer::query()->pluck('name')->sort()->values()->all());

    expect($names)->toBe(['Mine', 'Theirs']);
});

it('restores the fail-closed state after the block, even when it throws', function () {
    // A leaked escape hatch would silently disable isolation for the rest of
    // the request, which is worse than never having had one.
    app()->bind('tenant.id', fn () => null);

    try {
        Tenancy::withoutTenant(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    expect(fn () => Customer::query()->get())->toThrow(TenantContextMissing::class);
});

it('still stamps business_id on create from the bound tenant', function () {
    $a = Business::factory()->create();
    app()->bind('tenant.id', fn () => $a->id);

    $c = Customer::create([
        'uuid' => (string) Str::uuid(), 'name' => 'Ram', 'opening_balance' => '0.00',
    ]);

    expect($c->business_id)->toBe($a->id);
});

it('trips on a raw builder query that bypasses the global scope', function () {
    // A global scope binds Eloquent only. DB::table() walks straight past it,
    // and the report services use raw builders -- so this is the hole the
    // scope structurally cannot close.
    $b = Business::factory()->create();
    app()->bind('tenant.id', fn () => $b->id);

    expect(fn () => DB::table('customers')->get())
        ->toThrow(RuntimeException::class, 'without a business_id predicate');
});

it('allows a raw query that does scope itself', function () {
    $b = Business::factory()->create();
    app()->bind('tenant.id', fn () => $b->id);

    expect(DB::table('customers')->where('business_id', $b->id)->get())->toHaveCount(0);
});
