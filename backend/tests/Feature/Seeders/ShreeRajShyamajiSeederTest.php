<?php
// tests/Feature/Seeders/ShreeRajShyamajiSeederTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\MaterialConsumption;
use App\Models\PackSize;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductPack;
use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\CogsService;
use App\Services\KhataService;
use App\Services\StockService;
use Database\Seeders\ShreeRajShyamajiSeeder;

/** The business the seeder creates, looked up by its seeded name. */
function srsBusiness(): Business
{
    return Business::where('name', 'Shree Raj Shyama Ji Namkeen')->firstOrFail();
}

function srsCount(string $class): int
{
    return $class::where('business_id', srsBusiness()->id)->count();
}

beforeEach(function () {
    $this->seed(ShreeRajShyamajiSeeder::class);

    // The seeder runs inside Tenancy::withoutTenant() and leaves no tenant
    // bound. Every assertion below reads this shop's own rows, so bind it once
    // here rather than wrapping each one -- and bind it rather than suspending
    // the scope, so a query that reached another tenant would still be caught.
    app()->bind('tenant.id', fn () => srsBusiness()->id);
});

it('seeds the masters onto one business', function () {
    expect(srsCount(Customer::class))->toBe(40)
        ->and(srsCount(Supplier::class))->toBe(6)
        ->and(srsCount(RawMaterial::class))->toBe(16)
        ->and(srsCount(Product::class))->toBe(3)
        ->and(srsCount(PackSize::class))->toBe(17)
        ->and(srsCount(ProductPack::class))->toBe(21);
});

it('keeps same-named customers in different villages apart', function () {
    $rows = Customer::where('business_id', srsBusiness()->id)
        ->where('name', 'Santosh Singh')
        ->orderBy('village')
        ->pluck('village')
        ->all();

    expect($rows)->toBe(['Aziz', 'Harpur']);
});

it('records oil in the unit it is bought in', function () {
    $oil = RawMaterial::where('business_id', srsBusiness()->id)
        ->where('name', 'Refined Oil')
        ->firstOrFail();

    expect($oil->unit)->toBe('tina');
});

it('seeds every purchase with the total the invoices add up to', function () {
    $rows = Purchase::where('business_id', srsBusiness()->id)->get();

    expect($rows)->toHaveCount(23);

    $total = $rows->reduce(fn (string $c, $p) => bcadd($c, (string) $p->total, 2), '0.00');
    expect($total)->toBe('342305.00');
});

it('raises stock for every purchase, so on-hand is not zero', function () {
    // on-hand is a sum over stock_movements, not a column: a Purchase row alone
    // moves nothing. PurchaseWriter pairs each with a positive `in` movement.
    $ins = StockMovement::where('business_id', srsBusiness()->id)
        ->whereNotNull('purchase_id')
        ->get();

    expect($ins)->toHaveCount(23);
    expect($ins->every(fn ($m) => bccomp((string) $m->qty, '0', 3) > 0))->toBeTrue();
});

it('seeds the sale lines and groups them into sales by customer and date', function () {
    $lines = SaleLine::where('business_id', srsBusiness()->id)->get();
    $sales = Sale::where('business_id', srsBusiness()->id)->get();

    expect($lines)->toHaveCount(103);
    expect($sales)->toHaveCount(59);

    $total = $sales->reduce(fn (string $c, $s) => bcadd($c, (string) $s->total, 2), '0.00');
    expect($total)->toBe('169123.00');
});

it('holds the writer invariant that a sale equals the sum of its lines', function () {
    $sales = Sale::where('business_id', srsBusiness()->id)->get();

    foreach ($sales as $sale) {
        $sum = SaleLine::where('sale_id', $sale->id)->get()
            ->reduce(fn (string $c, $l) => bcadd($c, (string) $l->line_total, 2), '0.00');

        expect($sum)->toBe(number_format((float) $sale->total, 2, '.', ''), "sale {$sale->id}");
    }
});

it('keeps the return as a negative line rather than deleting the sale', function () {
    // Byash ji returned 9 of 15 packs on 11-Jun. Reversals stay as rows so
    // outstanding remains recomputable (PRD §9).
    $line = SaleLine::where('business_id', srsBusiness()->id)
        ->where('qty', '<', 0)
        ->firstOrFail();

    expect($line->qty)->toBe(-9)
        ->and((string) $line->line_total)->toBe('-666.00');
});

it('leaves the outstanding the owner is actually owed', function () {
    $payments = Payment::where('business_id', srsBusiness()->id)->get();

    expect($payments)->toHaveCount(46);

    $paid = $payments->reduce(fn (string $c, $p) => bcadd($c, (string) $p->amount, 2), '0.00');
    expect($paid)->toBe('126229.00');
});

it('leaves each customer owing what the owner ledger says', function () {
    // Hand-checked against the bill ledger. These are the numbers a shopkeeper
    // would recognise, so they are the ones worth pinning.
    $khata = app(KhataService::class);
    $expected = [
        'Ghore lal|Mathauli' => '9365.00',    // the biggest debtor
        'Bhim ji|Mathauli' => '6600.00',
        'Byash ji|Bhaisahi' => '985.00',      // after the 9-pack return
        'Manish ji|Hata' => '5.00',           // a Rs 9,125 bill paid Rs 9,120
        'Anarudh|Bhiswan' => '0.00',          // settled in full
        'Mishra ji|Tinahawan' => '0.00',      // in the master, never bought
    ];

    foreach ($expected as $key => $due) {
        [$name, $village] = explode('|', $key);
        $customer = Customer::where('business_id', srsBusiness()->id)
            ->where('name', $name)->where('village', $village)
            ->firstOrFail();

        expect($khata->outstandingFor($customer))->toBe($due, $key);
    }
});

it('totals the outstanding across the whole book', function () {
    $business = srsBusiness();

    $sales = Sale::where('business_id', $business->id)->get()
        ->reduce(fn (string $c, $s) => bcadd($c, (string) $s->total, 2), '0.00');
    $paid = Payment::where('business_id', $business->id)->get()
        ->reduce(fn (string $c, $p) => bcadd($c, (string) $p->amount, 2), '0.00');

    expect(bcsub($sales, $paid, 2))->toBe('42894.00');
});

it('charges each customer the rate they were actually given', function () {
    // Senvda 800g runs Rs 72 to Rs 80 depending on the customer. A seeder that
    // used the pack default everywhere would erase that.
    $rates = SaleLine::join('product_packs as pp', 'pp.id', '=', 'sale_lines.product_pack_id')
        ->join('pack_sizes as ps', 'ps.id', '=', 'pp.pack_size_id')
        ->join('products as p', 'p.id', '=', 'pp.product_id')
        ->where('sale_lines.business_id', srsBusiness()->id)
        ->where('p.name_en', 'Senvda')->where('ps.label', '800g')
        ->distinct()->pluck('sale_lines.rate')->map(fn ($r) => (float) $r)->all();

    expect(min($rates))->toBe(72.0)->and(max($rates))->toBe(80.0);
});

it('seeds the reconstructed batches', function () {
    $batches = ProductionBatch::where('business_id', srsBusiness()->id)->get();

    expect($batches)->toHaveCount(7);

    $output = $batches->reduce(fn (string $c, $b) => bcadd($c, (string) $b->output_kg, 3), '0.000');
    expect($output)->toBe('1945.000');
});

it('never lets a material close below zero', function () {
    // The check that catches a bad recipe. Consuming stock that was never
    // bought would make the whole valuation fiction.
    $stock = app(StockService::class);

    foreach (RawMaterial::where('business_id', srsBusiness()->id)->get() as $material) {
        $onHand = $stock->onHandFor($material);

        expect(bccomp($onHand, '0.000', 3))->toBeGreaterThanOrEqual(0, "{$material->name} closed at {$onHand}");
    }
});

it('costs every product below what it sells for', function () {
    // The point of reconstructing production: the owner's own consumption
    // figures give Rs 304/kg against Rs 102/kg of revenue.
    //
    // Pinned inside pwInTenant because CogsService reads through DB::table,
    // which carries its own business_id predicate rather than inheriting the
    // Eloquent scope the services above rely on. Unpinned it would see an empty
    // tenant.
    $revenuePerKg = ['Senvda' => 92.77, 'Sev' => 114.42, 'Mix Sev' => 127.30];
    $businessId = srsBusiness()->id;
    $costs = pwInTenant($businessId, fn () => app(CogsService::class)->packCosts($businessId));

    expect($costs)->not->toBeEmpty();

    foreach (ProductPack::where('business_id', $businessId)->get() as $pack) {
        $cost = $costs[$pack->id] ?? null;
        if ($cost === null) {
            continue;
        }

        $product = Product::find($pack->product_id);
        $size = PackSize::find($pack->pack_size_id);
        $costPerKg = (float) $cost->costRupees / (float) $size->weight_kg;
        $margin = ($revenuePerKg[$product->name_en] - $costPerKg) / $revenuePerKg[$product->name_en];

        expect($margin)->toBeGreaterThan(0.20)->toBeLessThan(0.40);
    }
});

it('is idempotent, so a second db:seed does not double the books', function () {
    $before = [
        Customer::where('business_id', srsBusiness()->id)->count(),
        Sale::where('business_id', srsBusiness()->id)->count(),
        SaleLine::where('business_id', srsBusiness()->id)->count(),
        Payment::where('business_id', srsBusiness()->id)->count(),
        Purchase::where('business_id', srsBusiness()->id)->count(),
        ProductionBatch::where('business_id', srsBusiness()->id)->count(),
    ];

    $this->seed(ShreeRajShyamajiSeeder::class);   // beforeEach already ran it once

    expect([
        Customer::where('business_id', srsBusiness()->id)->count(),
        Sale::where('business_id', srsBusiness()->id)->count(),
        SaleLine::where('business_id', srsBusiness()->id)->count(),
        Payment::where('business_id', srsBusiness()->id)->count(),
        Purchase::where('business_id', srsBusiness()->id)->count(),
        ProductionBatch::where('business_id', srsBusiness()->id)->count(),
    ])->toBe($before);
});

it('leaves no demo tenant behind', function () {
    expect(Business::whereIn('name', [
        'Demo Namkeen Bhandar', 'Demo Sweets House',
    ])->count())->toBe(0);
});
