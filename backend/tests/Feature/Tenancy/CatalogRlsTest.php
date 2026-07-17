<?php
// tests/Feature/Tenancy/CatalogRlsTest.php

use App\Models\Business;
use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('hides another business products even with the app layer bypassed', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    Product::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'name_hi' => 'हल्दी',
    ]);

    DB::transaction(function () use ($mine) {
        TenantContext::switchTo($mine->id);

        // Raw query builder: no Eloquent, no global scope. Anything returned
        // here got past RLS itself.
        $visible = DB::table('products')->count();

        expect($visible)->toBe(0);
    });
});

it('blocks inserting a product for a business other than the current tenant', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    expect(function () use ($mine, $theirs) {
        DB::transaction(function () use ($mine, $theirs) {
            TenantContext::switchTo($mine->id);

            DB::table('products')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $theirs->id, // mismatched on purpose
                'name_hi' => 'चोरी',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});

it('blocks inserting a pack size for another tenant', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    expect(function () use ($mine, $theirs) {
        DB::transaction(function () use ($mine, $theirs) {
            TenantContext::switchTo($mine->id);

            DB::table('pack_sizes')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $theirs->id,
                'label' => '500g',
                'weight_kg' => '0.500',
                'in_dropdown' => true,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});

it('shows a business its own products', function () {
    $mine = Business::factory()->create();

    Product::on('pgsql_migrate')->create([
        'business_id' => $mine->id, 'name_hi' => 'सेव',
    ]);

    DB::transaction(function () use ($mine) {
        TenantContext::switchTo($mine->id);

        expect(DB::table('products')->count())->toBe(1);
    });
});
