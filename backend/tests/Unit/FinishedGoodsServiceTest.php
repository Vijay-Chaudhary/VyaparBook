<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\ProductionBatch;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use App\Services\FinishedGoodsService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function inFgTenant(string $businessId, callable $fn): mixed
{
    return DB::transaction(function () use ($businessId, $fn) {
        TenantContext::switchTo($businessId);
        app()->bind('tenant.id', fn () => $businessId);

        return $fn();
    });
}

function fgProduct(Business $b, string $name = 'Aloo Bhujia'): Product
{
    return Product::create([
        'business_id' => $b->id, 'name_hi' => $name, 'name_en' => $name,
    ]);
}

function fgPack(Business $b, Product $p, string $label, string $weightKg): ProductPack
{
    $size = PackSize::firstOrCreate(
        ['business_id' => $b->id, 'label' => $label],
        ['weight_kg' => $weightKg],
    );

    return ProductPack::create([
        'business_id' => $b->id, 'product_id' => $p->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '50.00',
    ]);
}

function fgProduce(Product $p, User $u, string $outputKg, string $date = '2026-07-01'): void
{
    $batch = new ProductionBatch([
        'business_id' => $p->business_id, 'uuid' => (string) Str::uuid(),
        'product_id' => $p->id, 'batch_date' => $date, 'output_kg' => $outputKg,
    ]);
    $batch->created_by = $u->id;
    $batch->save();
}

/** A sale of $qty packs; negative qty is a return, as the domain allows. */
function fgSell(ProductPack $pack, User $u, int $qty, string $date = '2026-07-05'): Sale
{
    $customer = Customer::firstOrCreate(
        ['business_id' => $pack->business_id, 'name' => 'Cust'],
        ['uuid' => (string) Str::uuid(), 'village' => 'V', 'opening_balance' => '0.00'],
    );

    $sale = new Sale([
        'business_id' => $pack->business_id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'sale_date' => $date,
    ]);
    $sale->total = '100.00';
    $sale->created_by = $u->id;
    $sale->save();

    $line = new SaleLine([
        'business_id' => $pack->business_id, 'sale_id' => $sale->id,
        'product_pack_id' => $pack->id, 'qty' => $qty, 'rate' => '50.00',
    ]);
    $line->line_total = '100.00';
    $line->save();

    return $sale;
}

/** @return list<App\Reports\FinishedGoodsRow> */
function onHandFor(Business $b): array
{
    return inFgTenant($b->id, fn () => app(FinishedGoodsService::class)->onHand($b->id));
}

it('reports what was produced when nothing has been sold', function () {
    $b = tenantBusiness();
    $u = User::factory()->create();
    fgProduce(fgProduct($b), $u, '20.000');

    $rows = onHandFor($b);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->producedKg)->toBe('20.000');
    expect($rows[0]->soldKg)->toBe('0.000');
    expect($rows[0]->onHandKg)->toBe('20.000');
});

it('converts sold packs to kg by their own pack weight', function () {
    $b = tenantBusiness();
    $u = User::factory()->create();
    $product = fgProduct($b);
    fgProduce($product, $u, '20.000');

    // 10 × 200g = 2kg sold.
    fgSell(fgPack($b, $product, '200g', '0.200'), $u, 10);

    $rows = onHandFor($b);

    expect($rows[0]->soldKg)->toBe('2.000');
    expect($rows[0]->onHandKg)->toBe('18.000');
});

it('sums several pack sizes of the same product', function () {
    $b = tenantBusiness();
    $u = User::factory()->create();
    $product = fgProduct($b);
    fgProduce($product, $u, '20.000');

    fgSell(fgPack($b, $product, '200g', '0.200'), $u, 5);   // 1.000
    fgSell(fgPack($b, $product, '1kg', '1.000'), $u, 3);    // 3.000

    expect(onHandFor($b)[0]->soldKg)->toBe('4.000');
    expect(onHandFor($b)[0]->onHandKg)->toBe('16.000');
});

it('self-nets a return, because a return is a negative-qty line', function () {
    $b = tenantBusiness();
    $u = User::factory()->create();
    $product = fgProduct($b);
    fgProduce($product, $u, '20.000');
    $pack = fgPack($b, $product, '1kg', '1.000');

    fgSell($pack, $u, 5);
    fgSell($pack, $u, -2);   // two came back

    expect(onHandFor($b)[0]->soldKg)->toBe('3.000');
    expect(onHandFor($b)[0]->onHandKg)->toBe('17.000');
});

it('self-nets a full reversal without excluding any row', function () {
    $b = tenantBusiness();
    $u = User::factory()->create();
    $product = fgProduct($b);
    fgProduce($product, $u, '20.000');
    $pack = fgPack($b, $product, '1kg', '1.000');

    $sale = fgSell($pack, $u, 4);

    // A void: negated lines against the original, exactly as SaleController writes it.
    $reversal = new Sale([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $sale->customer_id, 'sale_date' => '2026-07-06',
        'reverses_id' => $sale->id,
    ]);
    $reversal->total = '-100.00';
    $reversal->created_by = $u->id;
    $reversal->save();

    $line = new SaleLine([
        'business_id' => $b->id, 'sale_id' => $reversal->id,
        'product_pack_id' => $pack->id, 'qty' => -4, 'rate' => '50.00',
    ]);
    $line->line_total = '-100.00';
    $line->save();

    expect(onHandFor($b)[0]->soldKg)->toBe('0.000');
    expect(onHandFor($b)[0]->onHandKg)->toBe('20.000');
});

it('shows a negative on-hand rather than hiding a data error', function () {
    $b = tenantBusiness();
    $u = User::factory()->create();
    $product = fgProduct($b);
    // Sold without ever recording production — the owner needs to see this.
    fgSell(fgPack($b, $product, '1kg', '1.000'), $u, 3);

    $rows = onHandFor($b);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->onHandKg)->toBe('-3.000');
});

it('omits a product that was never produced or sold', function () {
    $b = tenantBusiness();
    fgProduct($b, 'Never Touched');

    expect(onHandFor($b))->toBeEmpty();
});

it('excludes archived products', function () {
    $b = tenantBusiness();
    $u = User::factory()->create();
    $product = fgProduct($b);
    fgProduce($product, $u, '20.000');
    $product->archived_at = now();
    $product->save();

    expect(onHandFor($b))->toBeEmpty();
});

it('never counts another tenant\'s production or sales', function () {
    $mine = tenantBusiness();
    $theirs = tenantBusiness();
    $u = User::factory()->create();

    fgProduce(fgProduct($mine, 'Mine'), $u, '20.000');
    fgProduce(fgProduct($theirs, 'Theirs'), $u, '99.000');

    $rows = onHandFor($mine);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->name)->toBe('Mine');
    expect($rows[0]->producedKg)->toBe('20.000');
});
