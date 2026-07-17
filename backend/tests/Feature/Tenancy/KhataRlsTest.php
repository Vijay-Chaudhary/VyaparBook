<?php
// tests/Feature/Tenancy/KhataRlsTest.php
//
// Proves the khata RLS policies themselves, with the app layer removed. Uses the
// query builder rather than Eloquent so BelongsToTenant's global scope cannot
// mask whether RLS is doing the work — the whole point of this file. Mirrors
// CatalogRlsTest.

use App\Models\Business;
use App\Models\Customer;
use App\Models\PackSize;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Seed a full khata for one business on the migrate connection (bypasses RLS). */
function seedForeignKhata(Business $business): void
{
    $user = User::factory()->create();
    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(), 'name' => 'Theirs',
    ]);
    $product = Product::on('pgsql_migrate')->create(['business_id' => $business->id, 'name_hi' => 'सेव']);
    $packSize = PackSize::on('pgsql_migrate')->create(['business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500']);
    $pack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $packSize->id, 'default_sell_price' => '90.00',
    ]);

    $sale = new Sale([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'sale_date' => '2026-07-17',
    ]);
    $sale->setConnection('pgsql_migrate');
    $sale->created_by = $user->id;
    $sale->total = '90.00';
    $sale->save();

    $line = new SaleLine([
        'business_id' => $business->id, 'sale_id' => $sale->id,
        'product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00',
    ]);
    $line->setConnection('pgsql_migrate');
    $line->line_total = '90.00';
    $line->save();

    $payment = new Payment([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'payment_date' => '2026-07-17', 'amount' => '50.00', 'mode' => 'cash',
    ]);
    $payment->setConnection('pgsql_migrate');
    $payment->created_by = $user->id;
    $payment->save();
}

it('hides another business khata rows even with the app layer bypassed', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    seedForeignKhata($theirs);

    DB::transaction(function () use ($mine) {
        TenantContext::switchTo($mine->id);

        // Raw query builder: no Eloquent, no global scope. Anything returned here
        // got past RLS itself.
        expect(DB::table('customers')->count())->toBe(0);
        expect(DB::table('sales')->count())->toBe(0);
        expect(DB::table('sale_lines')->count())->toBe(0);
        expect(DB::table('payments')->count())->toBe(0);
    });
});

it('blocks inserting a customer for another tenant', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();

    expect(function () use ($mine, $theirs) {
        DB::transaction(function () use ($mine, $theirs) {
            TenantContext::switchTo($mine->id);

            DB::table('customers')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $theirs->id, // mismatched on purpose
                'uuid' => (string) Str::uuid(),
                'name' => 'चोरी',
                'opening_balance' => 0,
                'version' => 1,
                'sync_seq' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});

it('blocks inserting a payment for another tenant', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $user = User::factory()->create();
    $theirCustomer = Customer::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'uuid' => (string) Str::uuid(), 'name' => 'Theirs',
    ]);

    expect(function () use ($mine, $theirs, $theirCustomer, $user) {
        DB::transaction(function () use ($mine, $theirs, $theirCustomer, $user) {
            TenantContext::switchTo($mine->id);

            DB::table('payments')->insert([
                'id' => (string) Str::uuid(),
                'business_id' => $theirs->id, // mismatched; FKs below are valid
                'uuid' => (string) Str::uuid(),
                'customer_id' => $theirCustomer->id,
                'payment_date' => '2026-07-17',
                'amount' => 100,
                'mode' => 'cash',
                'created_by' => $user->id,
                'version' => 1,
                'sync_seq' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    })->toThrow(\Illuminate\Database\QueryException::class);
});

it('shows a business its own khata rows', function () {
    $mine = Business::factory()->create();
    seedForeignKhata($mine); // "foreign" helper, but seeded for mine here

    DB::transaction(function () use ($mine) {
        TenantContext::switchTo($mine->id);

        expect(DB::table('customers')->count())->toBe(1);
        expect(DB::table('sales')->count())->toBe(1);
        expect(DB::table('sale_lines')->count())->toBe(1);
        expect(DB::table('payments')->count())->toBe(1);
    });
});
