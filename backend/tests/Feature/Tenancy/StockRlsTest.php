<?php
// tests/Feature/Tenancy/StockRlsTest.php
//
// Proves the stock & production RLS policies themselves, with the app layer
// removed. Uses the query builder rather than Eloquent so BelongsToTenant's
// global scope cannot mask whether RLS is doing the work — the whole point of
// this file. Mirrors KhataRlsTest.

use App\Models\Business;
use App\Models\MaterialConsumption;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Seed a full stock+production set for one business on the migrate connection (bypasses RLS). */
function seedForeignStock(Business $business): void
{
    $user = User::factory()->create();
    $material = RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Besan', 'unit' => 'kg', 'reorder_level' => '10.000',
    ]);
    $product = Product::on('pgsql_migrate')->create(['business_id' => $business->id, 'name_hi' => 'सेव']);

    $batch = new ProductionBatch([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'product_id' => $product->id, 'batch_date' => '2026-07-15', 'output_kg' => '30.000',
    ]);
    $batch->setConnection('pgsql_migrate');
    $batch->created_by = $user->id;
    $batch->save();

    $consumption = new MaterialConsumption([
        'business_id' => $business->id, 'production_batch_id' => $batch->id,
        'raw_material_id' => $material->id, 'qty' => '25.000',
    ]);
    $consumption->setConnection('pgsql_migrate');
    $consumption->save();

    $movement = new StockMovement([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'raw_material_id' => $material->id, 'movement_date' => '2026-07-15',
        'kind' => 'out', 'qty' => '-25.000', 'production_batch_id' => $batch->id,
    ]);
    $movement->setConnection('pgsql_migrate');
    $movement->created_by = $user->id;
    $movement->save();
}

it('hides another business stock rows even with the app layer bypassed', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    seedForeignStock($theirs);

    DB::transaction(function () use ($mine) {
        TenantContext::switchTo($mine->id);

        // Raw query builder: no Eloquent, no global scope. Anything returned here
        // got past RLS itself.
        expect(DB::table('raw_materials')->count())->toBe(0);
        expect(DB::table('stock_movements')->count())->toBe(0);
        expect(DB::table('production_batches')->count())->toBe(0);
        expect(DB::table('material_consumptions')->count())->toBe(0);
    });
});

it('blocks inserting a raw material for another tenant', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    expect(function () use ($mine, $theirs) {
        DB::transaction(function () use ($mine, $theirs) {
            TenantContext::switchTo($mine->id);

            DB::table('raw_materials')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $theirs->id, // mismatched on purpose
                'uuid' => (string) Str::uuid(),
                'name' => 'चोरी',
                'unit' => 'kg',
                'version' => 1,
                'sync_seq' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});

it('blocks inserting a stock movement for another tenant', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $user = User::factory()->create();
    $theirMaterial = RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Theirs', 'unit' => 'kg',
    ]);

    expect(function () use ($mine, $theirs, $theirMaterial, $user) {
        DB::transaction(function () use ($mine, $theirs, $theirMaterial, $user) {
            TenantContext::switchTo($mine->id);

            DB::table('stock_movements')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $theirs->id, // mismatched; FKs below are valid
                'uuid' => (string) Str::uuid(),
                'raw_material_id' => $theirMaterial->id,
                'movement_date' => '2026-07-15',
                'kind' => 'in',
                'qty' => 10,
                'created_by' => $user->id,
                'version' => 1,
                'sync_seq' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});

it('shows a business its own stock rows', function () {
    $mine = Business::factory()->create();
    seedForeignStock($mine); // "foreign" helper, but seeded for mine here

    DB::transaction(function () use ($mine) {
        TenantContext::switchTo($mine->id);

        expect(DB::table('raw_materials')->count())->toBe(1);
        expect(DB::table('stock_movements')->count())->toBe(1);
        expect(DB::table('production_batches')->count())->toBe(1);
        expect(DB::table('material_consumptions')->count())->toBe(1);
    });
});
