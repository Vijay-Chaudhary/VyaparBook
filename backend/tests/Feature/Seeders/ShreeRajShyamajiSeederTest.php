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

/** The seeder writes on pgsql_migrate, so assertions read from there too. */
function srsBusiness(): Business
{
    return Business::on('pgsql_migrate')->where('name', 'Shree Raj Shyama Ji Namkeen')->firstOrFail();
}

function srsCount(string $class): int
{
    return $class::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count();
}

beforeEach(function () {
    $this->seed(ShreeRajShyamajiSeeder::class);
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
    $rows = Customer::on('pgsql_migrate')
        ->where('business_id', srsBusiness()->id)
        ->where('name', 'Santosh Singh')
        ->orderBy('village')
        ->pluck('village')
        ->all();

    expect($rows)->toBe(['Aziz', 'Harpur']);
});

it('records oil in the unit it is bought in', function () {
    $oil = RawMaterial::on('pgsql_migrate')
        ->where('business_id', srsBusiness()->id)
        ->where('name', 'Refined Oil')
        ->firstOrFail();

    expect($oil->unit)->toBe('tina');
});

it('seeds every purchase with the total the invoices add up to', function () {
    $rows = Purchase::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();

    expect($rows)->toHaveCount(23);

    $total = $rows->reduce(fn (string $c, $p) => bcadd($c, (string) $p->total, 2), '0.00');
    expect($total)->toBe('342305.00');
});

it('raises stock for every purchase, so on-hand is not zero', function () {
    // on-hand is a sum over stock_movements, not a column: a Purchase row alone
    // moves nothing. PurchaseWriter pairs each with a positive `in` movement.
    $ins = StockMovement::on('pgsql_migrate')
        ->where('business_id', srsBusiness()->id)
        ->whereNotNull('purchase_id')
        ->get();

    expect($ins)->toHaveCount(23);
    expect($ins->every(fn ($m) => bccomp((string) $m->qty, '0', 3) > 0))->toBeTrue();
});

it('seeds the sale lines and groups them into sales by customer and date', function () {
    $lines = SaleLine::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();
    $sales = Sale::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();

    expect($lines)->toHaveCount(103);
    expect($sales)->toHaveCount(59);

    $total = $sales->reduce(fn (string $c, $s) => bcadd($c, (string) $s->total, 2), '0.00');
    expect($total)->toBe('169123.00');
});

it('holds the writer invariant that a sale equals the sum of its lines', function () {
    $sales = Sale::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();

    foreach ($sales as $sale) {
        $sum = SaleLine::on('pgsql_migrate')->where('sale_id', $sale->id)->get()
            ->reduce(fn (string $c, $l) => bcadd($c, (string) $l->line_total, 2), '0.00');

        expect($sum)->toBe(number_format((float) $sale->total, 2, '.', ''), "sale {$sale->id}");
    }
});

it('keeps the return as a negative line rather than deleting the sale', function () {
    // Byash ji returned 9 of 15 packs on 11-Jun. Reversals stay as rows so
    // outstanding remains recomputable (PRD §9).
    $line = SaleLine::on('pgsql_migrate')
        ->where('business_id', srsBusiness()->id)
        ->where('qty', '<', 0)
        ->firstOrFail();

    expect($line->qty)->toBe(-9)
        ->and((string) $line->line_total)->toBe('-666.00');
});

it('leaves the outstanding the owner is actually owed', function () {
    $payments = Payment::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();

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
        $customer = Customer::on('pgsql_migrate')
            ->where('business_id', srsBusiness()->id)
            ->where('name', $name)->where('village', $village)
            ->firstOrFail();
        $customer->setConnection('pgsql_migrate');

        expect($khata->outstandingFor($customer))->toBe($due, $key);
    }
});

it('totals the outstanding across the whole book', function () {
    $business = srsBusiness();

    $sales = Sale::on('pgsql_migrate')->where('business_id', $business->id)->get()
        ->reduce(fn (string $c, $s) => bcadd($c, (string) $s->total, 2), '0.00');
    $paid = Payment::on('pgsql_migrate')->where('business_id', $business->id)->get()
        ->reduce(fn (string $c, $p) => bcadd($c, (string) $p->amount, 2), '0.00');

    expect(bcsub($sales, $paid, 2))->toBe('42894.00');
});

it('charges each customer the rate they were actually given', function () {
    // Senvda 800g runs Rs 72 to Rs 80 depending on the customer. A seeder that
    // used the pack default everywhere would erase that.
    $rates = SaleLine::on('pgsql_migrate')
        ->join('product_packs as pp', 'pp.id', '=', 'sale_lines.product_pack_id')
        ->join('pack_sizes as ps', 'ps.id', '=', 'pp.pack_size_id')
        ->join('products as p', 'p.id', '=', 'pp.product_id')
        ->where('sale_lines.business_id', srsBusiness()->id)
        ->where('p.name_en', 'Senvda')->where('ps.label', '800g')
        ->distinct()->pluck('sale_lines.rate')->map(fn ($r) => (float) $r)->all();

    expect(min($rates))->toBe(72.0)->and(max($rates))->toBe(80.0);
});

it('seeds the reconstructed batches', function () {
    $batches = ProductionBatch::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get();

    expect($batches)->toHaveCount(7);

    $output = $batches->reduce(fn (string $c, $b) => bcadd($c, (string) $b->output_kg, 3), '0.000');
    expect($output)->toBe('1945.000');
});

it('never lets a material close below zero', function () {
    // The check that catches a bad recipe. Consuming stock that was never
    // bought would make the whole valuation fiction.
    $stock = app(StockService::class);

    foreach (RawMaterial::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->get() as $material) {
        $onHand = $stock->onHandFor($material);

        expect(bccomp($onHand, '0.000', 3))->toBeGreaterThanOrEqual(0, "{$material->name} closed at {$onHand}");
    }
});

it('costs every product below what it sells for', function () {
    // The point of reconstructing production: the owner's own consumption
    // figures give Rs 304/kg against Rs 102/kg of revenue.
    //
    // Pinned inside pwInTenant because CogsService reads through DB::table on
    // the RLS-restricted app connection, unlike the services above that query
    // through model relations. Unpinned it would see an empty tenant.
    $revenuePerKg = ['Senvda' => 92.77, 'Sev' => 114.42, 'Mix Sev' => 127.30];
    $businessId = srsBusiness()->id;
    $costs = pwInTenant($businessId, fn () => app(CogsService::class)->packCosts($businessId));

    expect($costs)->not->toBeEmpty();

    foreach (ProductPack::on('pgsql_migrate')->where('business_id', $businessId)->get() as $pack) {
        $cost = $costs[$pack->id] ?? null;
        if ($cost === null) {
            continue;
        }

        $product = Product::on('pgsql_migrate')->find($pack->product_id);
        $size = PackSize::on('pgsql_migrate')->find($pack->pack_size_id);
        $costPerKg = (float) $cost->costRupees / (float) $size->weight_kg;
        $margin = ($revenuePerKg[$product->name_en] - $costPerKg) / $revenuePerKg[$product->name_en];

        expect($margin)->toBeGreaterThan(0.20)->toBeLessThan(0.40);
    }
});

it('is idempotent, so a second db:seed does not double the books', function () {
    $before = [
        Customer::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Sale::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        SaleLine::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Payment::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Purchase::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        ProductionBatch::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
    ];

    $this->seed(ShreeRajShyamajiSeeder::class);   // beforeEach already ran it once

    expect([
        Customer::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Sale::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        SaleLine::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Payment::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        Purchase::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
        ProductionBatch::on('pgsql_migrate')->where('business_id', srsBusiness()->id)->count(),
    ])->toBe($before);
});

it('leaves no demo tenant behind', function () {
    expect(Business::on('pgsql_migrate')->whereIn('name', [
        'Demo Namkeen Bhandar', 'Demo Sweets House',
    ])->count())->toBe(0);
});
