<?php
// tests/Unit/DashboardReportServiceTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\DashboardReportService;
use App\Services\KhataService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Run $fn inside a tenant-pinned transaction, as the controller does in prod. */
function inTenant(string $businessId, callable $fn): mixed
{
    return DB::transaction(function () use ($businessId, $fn) {
        TenantContext::switchTo($businessId);
        app()->bind('tenant.id', fn () => $businessId);

        return $fn();
    });
}



function dashPayment(Customer $c, User $u, string $amount, string $date): Payment
{
    $p = new Payment([
        'business_id' => $c->business_id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'payment_date' => $date, 'amount' => $amount, 'mode' => 'cash',
    ]);
    $p->setConnection('pgsql_migrate');
    $p->created_by = $u->id;
    $p->save();

    return $p;
}

it('totals customer outstanding as Σ of KhataService, and isolates tenants', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();  // a second tenant that must NOT leak in
    $u = User::factory()->create();

    $c1 = dashCustomer($a, 'Ramesh', '1500.00', 'Rampur');
    $c2 = dashCustomer($a, 'Mahaveer', '0.00');
    dashSale($c1, $u, '270.00', '2026-07-10');
    dashPayment($c1, $u, '200.00', '2026-07-12');   // c1 = 1500 + 270 - 200 = 1570.00
    dashSale($c2, $u, '58.50', '2026-07-11');        // c2 = 58.50

    $bOnly = dashCustomer($b, 'Other Shop Customer', '9999.00');

    $khata = new KhataService();
    $expectedTotal = bcadd($khata->outstandingFor($c1), $khata->outstandingFor($c2), 2);

    $summary = inTenant($a->id, fn () => app(DashboardReportService::class)
        ->customerOutstanding($a->id));

    expect($summary->totalRupees)->toBe($expectedTotal)   // '1628.50'
        ->and($summary->totalRupees)->toBe('1628.50');

    // Sorted highest-due first, and business b's customer is absent.
    expect($summary->customers)->toHaveCount(2);
    expect($summary->customers[0]->name)->toBe('Ramesh');
    expect(collect($summary->customers)->pluck('name'))->not->toContain('Other Shop Customer');
});

it('sums sales for today and the selected month, and builds a 12-row sales trend', function () {
    Illuminate\Support\Carbon::setTestNow('2026-07-22');

    $a = Business::factory()->create();
    $u = User::factory()->create();
    $c = dashCustomer($a, 'Ramesh');

    dashSale($c, $u, '100.00', '2026-07-22');  // today
    dashSale($c, $u, '40.00', '2026-07-05');   // this month, not today
    dashSale($c, $u, '25.00', '2026-05-09');   // May
    dashSale($c, $u, '9.00', '2025-07-01');    // different year — excluded

    [$salesToday, $salesMonth, $trend] = inTenant($a->id, function () use ($a) {
        $svc = app(DashboardReportService::class);

        return [
            $svc->salesToday($a->id),
            $svc->salesForMonth($a->id, 2026, 7),
            $svc->salesTrend($a->id, 2026),
        ];
    });

    expect($salesToday)->toBe('100.00');
    expect($salesMonth)->toBe('140.00');

    expect($trend)->toHaveCount(12);      // list<string>, index 0 = Jan
    expect($trend[6])->toBe('140.00');    // July
    expect($trend[4])->toBe('25.00');     // May
    expect($trend[0])->toBe('0.00');      // January, empty

    Illuminate\Support\Carbon::setTestNow();
});

function stockIn(App\Models\RawMaterial $m, User $u, string $qty): void
{
    $mv = new App\Models\StockMovement([
        'business_id' => $m->business_id, 'uuid' => (string) Str::uuid(),
        'raw_material_id' => $m->id, 'movement_date' => '2026-07-01',
        'kind' => 'in', 'qty' => $qty,
    ]);
    $mv->setConnection('pgsql_migrate');
    $mv->created_by = $u->id;
    $mv->save();
}


function dashBatch(Business $b, User $u, string $kg, string $date): void
{
    $batch = new App\Models\ProductionBatch([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'product_id' => App\Models\Product::on('pgsql_migrate')->create([
            'business_id' => $b->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
        ])->id,
        'batch_date' => $date, 'output_kg' => $kg,
    ]);
    $batch->setConnection('pgsql_migrate');
    $batch->created_by = $u->id;
    $batch->save();
}

function dashExpense(App\Models\Business $b, App\Models\User $u, string $category, string $amount, string $date, ?string $note = null): void
{
    $e = new App\Models\Expense([
        'business_id' => $b->id, 'uuid' => (string) Illuminate\Support\Str::uuid(),
        'category' => $category, 'amount' => $amount, 'spent_on' => $date, 'note' => $note,
    ]);
    $e->setConnection('pgsql_migrate');
    $e->created_by = $u->id;
    $e->save();
}

function dashSupplierPayment(App\Models\Business $b, App\Models\User $u, string $amount, string $date): void
{
    $s = App\Models\Supplier::on('pgsql_migrate')->firstOrCreate(
        ['business_id' => $b->id, 'name' => 'Supplier'],
        ['uuid' => (string) Illuminate\Support\Str::uuid(), 'opening_balance' => '0.00'],
    );
    $sp = new App\Models\SupplierPayment([
        'business_id' => $b->id, 'uuid' => (string) Illuminate\Support\Str::uuid(),
        'supplier_id' => $s->id, 'payment_date' => $date, 'amount' => $amount, 'mode' => 'cash',
    ]);
    $sp->setConnection('pgsql_migrate');
    $sp->created_by = $u->id;
    $sp->save();
}

it('sums production for the month and builds a 12-row kg trend', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();

    dashBatch($a, $u, '50.000', '2026-07-04');
    dashBatch($a, $u, '30.000', '2026-07-20');
    dashBatch($a, $u, '10.000', '2026-05-02');

    [$prodMonth, $trend] = inTenant($a->id, function () use ($a) {
        $svc = app(DashboardReportService::class);

        return [
            $svc->productionForMonth($a->id, 2026, 7),
            $svc->productionTrend($a->id, 2026),
        ];
    });

    expect($prodMonth)->toBe('80.000');

    expect($trend)->toHaveCount(12);
    expect($trend[6])->toBe('80.000');  // July
    expect($trend[4])->toBe('10.000');  // May
    expect($trend[0])->toBe('0.000');   // January
});

it('flags materials below reorder and ranks product performance', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();

    // Low stock: Besan on-hand 150 (reorder 100 → OK), Salt on-hand 4 (reorder 20 → LOW).
    $besan = App\Models\RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Besan', 'unit' => 'kg', 'reorder_level' => '100.000',
    ]);
    $salt = App\Models\RawMaterial::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Salt', 'unit' => 'kg', 'reorder_level' => '20.000',
    ]);
    stockIn($besan, $u, '150.000');
    stockIn($salt, $u, '4.000');

    // Product performance: two packs of one product sold this year.
    $product = App\Models\Product::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $ps = App\Models\PackSize::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'label' => '1kg', 'weight_kg' => '1.000',
    ]);
    $pack = App\Models\ProductPack::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'product_id' => $product->id, 'pack_size_id' => $ps->id,
        'default_sell_price' => '100.00', 'default_cost_price' => '93.00',
    ]);
    $c = dashCustomer($a, 'Ramesh');
    $sale = dashSale($c, $u, '1000.00', '2026-07-10');
    saleLine($sale, $pack, 10, '100.00');   // qty 10, sales 1000, cost 930, profit 70, margin 7.0%

    [$low, $perf] = inTenant($a->id, function () use ($a) {
        $svc = app(DashboardReportService::class);

        return [$svc->lowStock($a->id), $svc->productPerformance($a->id, 2026)];
    });

    expect(collect($low)->pluck('name'))->toContain('Salt')->not->toContain('Besan');

    expect($perf[0]->name)->toBe('Sev 1kg');
    expect($perf[0]->qtySold)->toBe(10);
    expect($perf[0]->salesRupees)->toBe('1000.00');
    expect($perf[0]->costRupees)->toBe('930.00');
    expect($perf[0]->profitRupees)->toBe('70.00');
    expect($perf[0]->marginPercent)->toBe('7.0');
});

it('assembles a full report, with highest-selling/profit and an empty-shop case', function () {
    Illuminate\Support\Carbon::setTestNow('2026-07-22');

    // Empty shop first: everything zero, nothing crashes.
    $empty = Business::factory()->create();
    $report = inTenant($empty->id, fn () => app(DashboardReportService::class)
        ->forMonth($empty->id, App\Reports\ReportPeriod::fromInput(2026, 7)));

    expect($report->salesMonthRupees)->toBe('0.00');
    expect($report->outstanding->totalRupees)->toBe('0.00');
    expect($report->lowStockCount)->toBe(0);
    expect($report->highestSellingName)->toBeNull();
    expect($report->trend)->toHaveCount(12);
    expect($report->grossProfitMonthRupees)->toBe('0.00');
    expect($report->expensesMonthRupees)->toBe('0.00');
    expect($report->netProfitMonthRupees)->toBe('0.00');
    expect($report->netProfitMarginPercent)->toBe('0.0');
    expect($report->cashInMonthRupees)->toBe('0.00');
    expect($report->netCashMonthRupees)->toBe('0.00');
    expect($report->cashPositionRupees)->toBe('0.00');
    expect($report->cashTrend)->toHaveCount(12);
    expect($report->finishedGoods)->toBe([]);   // empty shop: nothing made, nothing sold

    Illuminate\Support\Carbon::setTestNow();
});

it('computes an estimated monthly gross profit: sales minus product cost, before expenses', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();

    $product = App\Models\Product::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $ps = App\Models\PackSize::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'label' => '1kg', 'weight_kg' => '1.000',
    ]);
    $pack = App\Models\ProductPack::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'product_id' => $product->id, 'pack_size_id' => $ps->id,
        'default_sell_price' => '100.00', 'default_cost_price' => '93.00',
    ]);
    $c = dashCustomer($a, 'Ramesh');

    $jul = dashSale($c, $u, '1000.00', '2026-07-10');
    saleLine($jul, $pack, 10, '100.00');   // sales 1000, cost 930 → gross profit 70.00
    $may = dashSale($c, $u, '300.00', '2026-05-04');
    saleLine($may, $pack, 3, '100.00');    // sales 300, cost 279 → gross profit 21.00

    [$trend, $monthFigure] = inTenant($a->id, function () use ($a) {
        $svc = app(DashboardReportService::class);

        return [
            $svc->grossProfitTrend($a->id, 2026),
            $svc->forMonth($a->id, App\Reports\ReportPeriod::fromInput(2026, 7))->grossProfitMonthRupees,
        ];
    });

    expect($trend)->toHaveCount(12);
    expect($trend[6])->toBe('70.00');   // July
    expect($trend[4])->toBe('21.00');   // May
    expect($trend[0])->toBe('0.00');    // January
    expect($monthFigure)->toBe('70.00'); // selected month = July
});

it('totals expenses for a month, breaks them down by category, and builds a 12-row trend', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();   // second tenant — must NOT leak in
    $u = User::factory()->create();

    dashExpense($a, $u, 'rent', '5000.00', '2026-07-01');
    dashExpense($a, $u, 'salaries', '3000.00', '2026-07-05');
    dashExpense($a, $u, 'rent', '200.00', '2026-07-20');     // second rent in July
    dashExpense($a, $u, 'electricity', '800.00', '2026-05-04'); // May, different month
    dashExpense($b, $u, 'rent', '99999.00', '2026-07-02');    // other tenant

    [$month, $breakdown, $trend] = inTenant($a->id, function () use ($a) {
        $svc = app(DashboardReportService::class);

        return [
            $svc->expensesForMonth($a->id, 2026, 7),
            $svc->expensesByCategory($a->id, 2026, 7),
            $svc->expensesTrend($a->id, 2026),
        ];
    });

    expect($month)->toBe('8200.00');                 // 5000 + 3000 + 200

    // Breakdown ordered by canonical category order, zero categories omitted.
    expect(collect($breakdown)->pluck('category')->all())->toBe(['rent', 'salaries']);
    expect($breakdown[0]->amountRupees)->toBe('5200.00'); // rent 5000 + 200
    expect($breakdown[1]->amountRupees)->toBe('3000.00');

    expect($trend)->toHaveCount(12);
    expect($trend[6])->toBe('8200.00');  // July
    expect($trend[4])->toBe('800.00');   // May
    expect($trend[0])->toBe('0.00');     // January
});

it('assembles net profit for a profitable month, a loss month, and guards zero-sales margin', function () {
    Illuminate\Support\Carbon::setTestNow('2026-07-22');

    $a = Business::factory()->create();
    $u = User::factory()->create();

    // One product pack: sell 100, cost 93 → gross profit 7 per unit.
    $product = App\Models\Product::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $ps = App\Models\PackSize::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'label' => '1kg', 'weight_kg' => '1.000',
    ]);
    $pack = App\Models\ProductPack::on('pgsql_migrate')->create([
        'business_id' => $a->id, 'product_id' => $product->id, 'pack_size_id' => $ps->id,
        'default_sell_price' => '100.00', 'default_cost_price' => '93.00',
    ]);
    $c = dashCustomer($a, 'Ramesh');

    // July: sell 100 units → sales 10000, cost 9300, gross 700. Expenses 500 → net 200. margin 2.0%.
    $jul = dashSale($c, $u, '10000.00', '2026-07-10');
    saleLine($jul, $pack, 100, '100.00');
    dashExpense($a, $u, 'rent', '500.00', '2026-07-01');

    // June: sell 10 → sales 1000, cost 930, gross 70. Expenses 250 → net -180 (a loss).
    $jun = dashSale($c, $u, '1000.00', '2026-06-10');
    saleLine($jun, $pack, 10, '100.00');
    dashExpense($a, $u, 'salaries', '250.00', '2026-06-15');

    $report = inTenant($a->id, fn () => app(DashboardReportService::class)
        ->forMonth($a->id, App\Reports\ReportPeriod::fromInput(2026, 7)));

    // Selected month = July.
    expect($report->expensesMonthRupees)->toBe('500.00');
    expect($report->netProfitMonthRupees)->toBe('200.00');       // 700 − 500
    expect($report->netProfitMarginPercent)->toBe('2.0');        // 200 / 10000 * 100

    // Trend carries per-month expenses + net profit, incl. the June loss.
    expect($report->trend[6]->netProfitRupees)->toBe('200.00');  // July
    expect($report->trend[5]->netProfitRupees)->toBe('-180.00'); // June: 70 − 250
    expect($report->trend[5]->expensesRupees)->toBe('250.00');
    expect($report->trend[0]->netProfitRupees)->toBe('0.00');    // January

    // Breakdown for July.
    expect(collect($report->expenseBreakdown)->pluck('category')->all())->toBe(['rent']);

    Illuminate\Support\Carbon::setTestNow();
});

it('reports a zero net margin when there are no sales (no divide-by-zero)', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();
    dashExpense($a, $u, 'rent', '500.00', '2026-07-01');   // expense but no sales

    $report = inTenant($a->id, fn () => app(DashboardReportService::class)
        ->forMonth($a->id, App\Reports\ReportPeriod::fromInput(2026, 7)));

    expect($report->salesMonthRupees)->toBe('0.00');
    expect($report->netProfitMonthRupees)->toBe('-500.00');  // 0 gross − 500 expenses
    expect($report->netProfitMarginPercent)->toBe('0.0');    // guarded
});

it('costs gross profit from production, falling back to the estimate per product', function () {
    // Two products sold in the same month: one produced (so it has an ACTUAL
    // cost that differs from the owner's guess), one bought in (no batches, so
    // the guess still stands). The month's gross profit must mix both, and the
    // marker must report only the bought-in half as estimated.
    $a = Business::factory()->create();
    $u = User::factory()->create();

    $besan = pwMaterial($a, 'Besan');
    cogsBuy($a, $u, $besan, '100', '40');                    // ₹40/kg

    // Sev: 10kg produced from 10kg besan → ₹40/kg actual, though the owner
    // typed ₹93.00. A 1kg pack therefore costs ₹40.00, not ₹93.00.
    [$sev, $sevPack] = cogsProduct($a, 'Sev', '1.000', '93.00');
    cogsBatch($a, $u, $sev, '10.000', [$besan->id => '10.000']);

    // Namkeen: bought in, never produced → the ₹77.00 estimate stands.
    [, $namkeenPack] = cogsProduct($a, 'Namkeen', '1.000', '77.00');

    $c = dashCustomer($a, 'Ramesh');
    $sale = dashSale($c, $u, '2000.00', '2026-07-10');
    saleLine($sale, $sevPack, 10, '100.00');      // 1000 revenue, cost 10 × 40 = 400
    saleLine($sale, $namkeenPack, 10, '100.00');  // 1000 revenue, cost 10 × 77 = 770

    [$trend, $report, $perf] = inTenant($a->id, function () use ($a) {
        $svc = app(DashboardReportService::class);
        $r = $svc->forMonth($a->id, App\Reports\ReportPeriod::fromInput(2026, 7));

        return [$svc->grossProfitTrend($a->id, 2026), $r, $r->productPerformance];
    });

    // 2000 − (400 + 770) = 830. Under the old estimate it would have been
    // 2000 − (930 + 770) = 300 — production costing is what moves it.
    expect($trend[6])->toBe('830.00');
    expect($report->grossProfitMonthRupees)->toBe('830.00');

    // Only the bought-in product's revenue is still estimated.
    expect($report->estimatedCostRevenueRupees)->toBe('1000.00');

    // Product performance uses the same costing, so it cannot contradict above:
    // Σ of its per-pack profit is the headline gross profit.
    $sumOfPerf = collect($perf)->reduce(fn ($carry, $p) => bcadd($carry, $p->profitRupees, 2), '0.00');
    expect($sumOfPerf)->toBe('830.00');

    $sevRow = collect($perf)->firstWhere('name', 'Sev 1.000kg');
    expect($sevRow->costRupees)->toBe('400.00');   // actual, not 930.00
});

it('reports nothing as estimated once every product sold has been produced', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();

    $besan = pwMaterial($a, 'Besan');
    cogsBuy($a, $u, $besan, '100', '40');

    [$sev, $pack] = cogsProduct($a, 'Sev', '1.000', '93.00');
    cogsBatch($a, $u, $sev, '10.000', [$besan->id => '10.000']);

    $c = dashCustomer($a, 'Ramesh');
    $sale = dashSale($c, $u, '500.00', '2026-07-10');
    saleLine($sale, $pack, 5, '100.00');

    $report = inTenant($a->id, fn () => app(DashboardReportService::class)
        ->forMonth($a->id, App\Reports\ReportPeriod::fromInput(2026, 7)));

    expect($report->estimatedCostRevenueRupees)->toBe('0.00');
    expect($report->grossProfitMonthRupees)->toBe('300.00');   // 500 − 5 × 40
});

it('assembles the cash trend with a running position seeded from prior years', function () {
    Illuminate\Support\Carbon::setTestNow('2026-07-22');

    $a = Business::factory()->create();
    $u = User::factory()->create();
    $c = dashCustomer($a, 'Ramesh', '0.00');

    // 2025 (prior): collected 4000, paid supplier 1000 → opening 2026 = +3000.
    dashPayment($c, $u, '4000.00', '2025-12-01');
    dashSupplierPayment($a, $u, '1000.00', '2025-12-02');

    // June 2026: in 2000, supplier 500, expense 300 → net +1200.
    dashPayment($c, $u, '2000.00', '2026-06-05');
    dashSupplierPayment($a, $u, '500.00', '2026-06-06');
    dashExpense($a, $u, 'rent', '300.00', '2026-06-07');

    // July 2026: in 100, supplier 900, expense 400 → net −1200 (cash-negative).
    dashPayment($c, $u, '100.00', '2026-07-10');
    dashSupplierPayment($a, $u, '900.00', '2026-07-11');
    dashExpense($a, $u, 'salaries', '400.00', '2026-07-12');

    $report = inTenant($a->id, fn () => app(DashboardReportService::class)
        ->forMonth($a->id, App\Reports\ReportPeriod::fromInput(2026, 7)));

    // Selected month = July.
    expect($report->cashInMonthRupees)->toBe('100.00');
    expect($report->supplierPaidMonthRupees)->toBe('900.00');
    expect($report->expensePaidMonthRupees)->toBe('400.00');
    expect($report->netCashMonthRupees)->toBe('-1200.00');           // 100 − (900 + 400)

    // Running position: opening 3000 + June 1200 + July −1200 = 3000.
    expect($report->cashPositionRupees)->toBe('3000.00');

    // Trend rows: June net +1200, July net −1200; positions accumulate.
    expect($report->cashTrend)->toHaveCount(12);
    expect($report->cashTrend[5]->netCashRupees)->toBe('1200.00');   // June
    expect($report->cashTrend[5]->positionRupees)->toBe('4200.00');  // 3000 + 1200
    expect($report->cashTrend[6]->netCashRupees)->toBe('-1200.00');  // July
    // The selected-month headline equals that month's trend-row position — one walk.
    expect($report->cashTrend[6]->positionRupees)->toBe($report->cashPositionRupees);

    Illuminate\Support\Carbon::setTestNow();
});
