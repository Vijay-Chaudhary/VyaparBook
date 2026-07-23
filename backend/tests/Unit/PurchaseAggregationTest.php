<?php
// tests/Unit/PurchaseAggregationTest.php
//
// pwInTenant/pwSupplier/pwMaterial come from tests/Pest.php.

use App\Models\Business;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\PurchaseWriter;
use App\Services\StockService;
use App\Services\SupplierService;
use Illuminate\Support\Str;

it('computes supplier outstanding, weighted-avg cost/kg and stock value, isolating tenants', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();          // a second tenant that must NOT leak in
    $u = User::factory()->create();

    $sup = pwSupplier($a, 'Besan Traders', '500.00');  // opening owed 500
    $besan = pwMaterial($a, 'Besan');
    pwSupplier($b, 'Other Shop Supplier', '9999.00');  // b's supplier — must be absent from a

    $out = pwInTenant($a->id, function () use ($sup, $besan, $u) {
        app()->bind('tenant.user_id', fn () => $u->id);
        $writer = new PurchaseWriter();

        // Buy 100kg @ ₹40 and 50kg @ ₹46 → total ₹6,300, qty 150 → avg ₹42/kg.
        $writer->record(['uuid' => (string) Str::uuid(), 'supplier_id' => $sup->id, 'raw_material_id' => $besan->id,
            'purchase_date' => '2026-07-01', 'qty' => '100', 'unit_cost' => '40', 'note' => null]);
        $writer->record(['uuid' => (string) Str::uuid(), 'supplier_id' => $sup->id, 'raw_material_id' => $besan->id,
            'purchase_date' => '2026-07-05', 'qty' => '50', 'unit_cost' => '46', 'note' => null]);

        // Consume 120kg (a signed-negative 'out' movement) → on-hand 30.
        $mv = new StockMovement([
            'business_id' => app('tenant.id'), 'uuid' => (string) Str::uuid(),
            'raw_material_id' => $besan->id, 'movement_date' => '2026-07-10',
            'kind' => 'out', 'qty' => '-120.000', 'note' => 'consume',
        ]);
        $mv->created_by = app('tenant.user_id');
        $mv->save();

        // Pay the supplier ₹2,000.
        $sp = new SupplierPayment([
            'business_id' => app('tenant.id'), 'uuid' => (string) Str::uuid(),
            'supplier_id' => $sup->id, 'payment_date' => '2026-07-12', 'amount' => '2000', 'mode' => 'cash',
        ]);
        $sp->created_by = app('tenant.user_id');
        $sp->save();

        $supplierService = new SupplierService();
        $purchaseService = new PurchaseService(new StockService());
        $summary = $supplierService->outstandingSummary($besan->business_id);

        return [
            'supplierOut' => $supplierService->outstandingFor(Supplier::find($sup->id)),
            'summaryTotal' => $summary->totalRupees,
            'summaryNames' => collect($summary->suppliers)->pluck('name')->all(),
            'costPerKg' => $purchaseService->costPerKgFor(RawMaterial::find($besan->id)),
            'stockValue' => $purchaseService->stockValue($besan->business_id),
        ];
    });

    // 500 opening + 6300 purchases − 2000 payment = 4800.
    expect($out['supplierOut'])->toBe('4800.00');
    expect($out['summaryTotal'])->toBe('4800.00');
    expect($out['summaryNames'])->toBe(['Besan Traders'])          // b's supplier absent
        ->not->toContain('Other Shop Supplier');

    expect($out['costPerKg'])->toBe('42.00');   // 6300 / 150 weighted avg
    expect($out['stockValue'])->toBe('1260.00'); // on-hand 30 × 42
});

it('reports zero cost/kg and value for a material never purchased', function () {
    $a = Business::factory()->create();
    $salt = pwMaterial($a, 'Salt');

    $res = pwInTenant($a->id, function () use ($salt, $a) {
        $svc = new PurchaseService(new StockService());

        return [
            'cost' => $svc->costPerKgFor(RawMaterial::find($salt->id)),
            'value' => $svc->stockValue($a->id),
        ];
    });

    expect($res['cost'])->toBe('0.00');
    expect($res['value'])->toBe('0.00');
});
