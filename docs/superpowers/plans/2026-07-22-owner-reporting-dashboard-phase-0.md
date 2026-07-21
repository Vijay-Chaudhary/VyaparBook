# Owner Reporting Dashboard — Phase 0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Blade, online-only, owner-only management dashboard at `GET /reports/dashboard` that reports sales, customer outstanding, production, low-stock, product-wise performance, and monthly sales/production trends — computed only from data VyaparBook already stores (no new tables).

**Architecture:** One `DashboardReportService` owns all aggregation (set-based SQL, no N+1), scoped explicitly by `business_id` and run inside a tenant-pinned read transaction (RLS + app scope, defense in depth). A thin `Web\ReportController` resolves the owned business (never trusted from the request) via a shared `ResolvesOwnedTenant` trait, then renders a Blade view with server-generated inline-SVG charts. Every rupee figure is a bcmath decimal string — never a float — matching `KhataService`.

**Tech Stack:** PHP 8.3 / Laravel, PostgreSQL (RLS), Blade, Pest. Reuses `App\Services\KhataService` (formula source-of-truth), `App\Services\StockService` (low-stock), `App\Support\TenantContext`.

**Spec:** `docs/superpowers/specs/2026-07-22-owner-reporting-dashboard-phase-0-design.md`

---

## Before you start

- [ ] **Create a feature branch off `master`:**

```bash
git checkout master
git checkout -b feat/reporting-dashboard-phase-0
```

- Local services (Postgres, PgBouncer, Redis) must be running. If the suite cannot connect, ask the user to start them (they require the user's sudo on WSL).
- Run the whole suite once to confirm a green baseline: `./vendor/bin/pest`

### Conventions used throughout (read once)

- **Test data is written on the privileged `pgsql_migrate` connection** to bypass RLS during setup, exactly like `tests/Unit/KhataServiceTest.php`. Read paths (the service, the controller) use the default connection.
- **Money is a decimal string** (e.g. `'264004.00'`), summed with `bcadd`/`bcsub`/`bccomp` at scale 2. Never cast money to float. Postgres numeric sums are exact; select them `::text`.
- **Quantities (kg)** are scale-3 decimal strings, like `StockService`.
- Service queries **always** filter `->where('business_id', $businessId)` explicitly. This makes the service correct even in unit tests where no tenant is bound (the `BelongsToTenant` global scope is a no-op when `app('tenant.id')` is null). Inside the controller the tenant is also pinned, so RLS + scope apply on top — defense in depth.
- Product display name: `coalesce(products.name_en, products.name_hi)` + `' '` + `pack_sizes.label`.

---

## File structure

**Create:**
- `app/Support/Inr.php` — format a rupee decimal string with Indian digit grouping (₹2,64,004.00).
- `app/Reports/ReportPeriod.php` — value object: validated/clamped `(year, month)`.
- `app/Reports/DashboardReport.php` — readonly DTO returned by the service.
- `app/Reports/OutstandingSummary.php`, `app/Reports/CustomerDue.php`, `app/Reports/ProductPerf.php`, `app/Reports/TrendRow.php`, `app/Reports/LowStockRow.php` — readonly value objects the DTO holds.
- `app/Services/DashboardReportService.php` — all aggregation.
- `app/Http/Controllers/Concerns/ResolvesOwnedTenant.php` — shared owner-resolution + tenant-pinning trait.
- `app/Http/Controllers/Web/ReportController.php` — the route handler.
- `app/View/Components/SvgBarChart.php` + `resources/views/components/svg-bar-chart.blade.php` — inline SVG bar chart.
- `resources/views/reports/dashboard.blade.php` + `resources/views/reports/partials/{tiles,insights,products,trend,charts}.blade.php`.
- `lang/en/reports.php`, `lang/hi/reports.php`.
- Tests: `tests/Unit/InrTest.php`, `tests/Unit/ReportPeriodTest.php`, `tests/Unit/DashboardReportServiceTest.php`, `tests/Unit/SvgBarChartTest.php`, `tests/Feature/Web/ReportsDashboardTest.php`.

**Modify:**
- `routes/web.php` — add the route + import.
- `resources/js/screens/Home.jsx` — owner-only link to the dashboard.
- `resources/js/i18n.js` — add the `reports_dashboard` label key (both locales).

---

## Task 1: `Inr` — Indian-grouped rupee formatter

**Files:**
- Create: `app/Support/Inr.php`
- Test: `tests/Unit/InrTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/InrTest.php

use App\Support\Inr;

it('formats with Indian grouping and two decimals', function () {
    expect(Inr::format('264004.00'))->toBe('₹2,64,004.00');
    expect(Inr::format('107963'))->toBe('₹1,07,963.00');
    expect(Inr::format('999.5'))->toBe('₹999.50');
    expect(Inr::format('0'))->toBe('₹0.00');
});

it('shows negatives with a leading minus, not parentheses', function () {
    expect(Inr::format('-26504'))->toBe('−₹26,504.00');
});

it('can omit the symbol', function () {
    expect(Inr::format('1200000', withSymbol: false))->toBe('12,00,000.00');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/InrTest.php`
Expected: FAIL — class `App\Support\Inr` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// app/Support/Inr.php

namespace App\Support;

/**
 * Format a decimal rupee string with Indian digit grouping: ₹1,20,000.50.
 *
 * Not Western 120,000.50 — Indian grouping is 2,2,3 after the first three
 * digits, and getting it wrong makes the number read as a different amount to
 * the person whose money it is. Mirrors resources/js/offline/money.js
 * formatRupees so the printed report and the phone agree.
 *
 * Input is a bcmath-scale-2 decimal string (what the service produces); no
 * floats are involved. `intl` is not required — grouping is done by hand.
 */
class Inr
{
    public static function format(string $amount, bool $withSymbol = true): string
    {
        $negative = str_starts_with($amount, '-');
        $abs = ltrim($amount, '-');

        // Normalise to exactly two decimals via bcadd at scale 2 (truncates,
        // matching the server's bcmath discipline — never rounds up).
        $normalised = bcadd($abs === '' ? '0' : $abs, '0', 2);
        [$whole, $frac] = explode('.', $normalised);

        $grouped = self::groupIndian($whole);
        $sign = $negative ? '−' : '';          // U+2212 minus, like money.js
        $symbol = $withSymbol ? '₹' : '';

        return "{$sign}{$symbol}{$grouped}.{$frac}";
    }

    private static function groupIndian(string $whole): string
    {
        if (strlen($whole) <= 3) {
            return $whole;
        }

        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);
        // Group the remaining digits in pairs, from the right.
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);

        return "{$rest},{$last3}";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/InrTest.php`
Expected: PASS (3 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Inr.php tests/Unit/InrTest.php
git commit -m "feat: add Inr Indian-grouping rupee formatter"
```

---

## Task 2: `ReportPeriod` — validated year/month value object

**Files:**
- Create: `app/Reports/ReportPeriod.php`
- Test: `tests/Unit/ReportPeriodTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/ReportPeriodTest.php

use App\Reports\ReportPeriod;
use Illuminate\Support\Carbon;

it('keeps a valid year and month', function () {
    $p = ReportPeriod::fromInput(2025, 3);
    expect($p->year)->toBe(2025)->and($p->month)->toBe(3);
});

it('clamps an out-of-range month into 1..12', function () {
    expect(ReportPeriod::fromInput(2025, 0)->month)->toBe(1);
    expect(ReportPeriod::fromInput(2025, 13)->month)->toBe(12);
});

it('clamps the year to a sane window and falls back to now for nulls', function () {
    Carbon::setTestNow('2026-07-22');

    expect(ReportPeriod::fromInput(1900, 5)->year)->toBe(2020);   // floor
    expect(ReportPeriod::fromInput(3000, 5)->year)->toBe(2026);   // ceil = current year
    expect(ReportPeriod::fromInput(null, null)->year)->toBe(2026);
    expect(ReportPeriod::fromInput(null, null)->month)->toBe(7);

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/ReportPeriodTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// app/Reports/ReportPeriod.php

namespace App\Reports;

use Illuminate\Support\Carbon;

/**
 * The month/year a dashboard is being viewed for. Constructed from raw request
 * input, so it validates and clamps rather than trusting: a hand-edited query
 * string can never push the service outside a sane window.
 */
final readonly class ReportPeriod
{
    private function __construct(
        public int $year,
        public int $month,
    ) {}

    public static function fromInput(?int $year, ?int $month): self
    {
        $now = Carbon::now();
        $currentYear = (int) $now->year;

        $year ??= $currentYear;
        $month ??= (int) $now->month;

        $year = max(2020, min($currentYear, $year));
        $month = max(1, min(12, $month));

        return new self($year, $month);
    }

    /** First day of the selected month, for range queries. */
    public function startOfMonth(): Carbon
    {
        return Carbon::create($this->year, $this->month, 1)->startOfDay();
    }

    public function endOfMonth(): Carbon
    {
        return $this->startOfMonth()->endOfMonth();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/ReportPeriodTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Reports/ReportPeriod.php tests/Unit/ReportPeriodTest.php
git commit -m "feat: add ReportPeriod value object with clamping"
```

---

## Task 3: Report value objects (readonly DTOs)

These are pure data holders the service will fill. No behaviour, so no dedicated test — they are exercised by the service tests in later tasks.

**Files:**
- Create: `app/Reports/CustomerDue.php`, `app/Reports/OutstandingSummary.php`, `app/Reports/ProductPerf.php`, `app/Reports/TrendRow.php`, `app/Reports/LowStockRow.php`, `app/Reports/DashboardReport.php`

- [ ] **Step 1: Create the value objects**

```php
<?php
// app/Reports/CustomerDue.php
namespace App\Reports;

final readonly class CustomerDue
{
    public function __construct(
        public string $name,
        public ?string $village,
        public string $outstandingRupees, // decimal string, may be negative
    ) {}
}
```

```php
<?php
// app/Reports/OutstandingSummary.php
namespace App\Reports;

final readonly class OutstandingSummary
{
    /** @param list<CustomerDue> $customers */
    public function __construct(
        public string $totalRupees,
        public array $customers,
    ) {}
}
```

```php
<?php
// app/Reports/ProductPerf.php
namespace App\Reports;

final readonly class ProductPerf
{
    public function __construct(
        public string $name,
        public int $qtySold,
        public string $salesRupees,
        public string $estCostRupees,
        public string $estProfitRupees,
        public string $marginPercent, // "4.9" (one decimal), "0.0" when sales are 0
    ) {}
}
```

```php
<?php
// app/Reports/TrendRow.php
namespace App\Reports;

final readonly class TrendRow
{
    public function __construct(
        public int $month,            // 1..12
        public string $salesRupees,
        public string $productionKg,  // scale-3 decimal string
    ) {}
}
```

```php
<?php
// app/Reports/LowStockRow.php
namespace App\Reports;

final readonly class LowStockRow
{
    public function __construct(
        public string $name,
        public string $onHand,   // scale-3 decimal string
        public string $reorder,  // scale-3 decimal string
    ) {}
}
```

```php
<?php
// app/Reports/DashboardReport.php
namespace App\Reports;

final readonly class DashboardReport
{
    /**
     * @param list<LowStockRow>  $lowStock
     * @param list<ProductPerf>  $productPerformance
     * @param list<TrendRow>     $trend  exactly 12 rows, Jan..Dec
     */
    public function __construct(
        public ReportPeriod $period,
        public string $salesTodayRupees,
        public string $salesMonthRupees,
        public OutstandingSummary $outstanding,
        public string $productionMonthKg,
        public array $lowStock,
        public int $lowStockCount,
        public array $productPerformance,
        public ?string $highestSellingName,
        public ?string $highestProfitName,
        public array $trend,
    ) {}
}
```

- [ ] **Step 2: Verify they load**

Run: `php -r "require 'vendor/autoload.php'; new App\Reports\CustomerDue('a', null, '0.00'); echo 'ok';"`
Expected: prints `ok`.

- [ ] **Step 3: Commit**

```bash
git add app/Reports/
git commit -m "feat: add dashboard report value objects"
```

---

## Task 4: `DashboardReportService::customerOutstanding` (with khata parity)

**Files:**
- Create: `app/Services/DashboardReportService.php`
- Test: `tests/Unit/DashboardReportServiceTest.php`

- [ ] **Step 1: Write the failing test**

Reuse the same row helpers as `KhataServiceTest`. This test both checks the numbers and proves the parity invariant and tenant isolation.

```php
<?php
// tests/Unit/DashboardReportServiceTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\DashboardReportService;
use App\Services\KhataService;
use Illuminate\Support\Str;

function dashCustomer(Business $b, string $name, string $opening = '0.00', ?string $village = null): Customer
{
    return Customer::on('pgsql_migrate')->create([
        'business_id' => $b->id,
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'village' => $village,
        'opening_balance' => $opening,
    ]);
}

function dashSale(Customer $c, User $u, string $total, string $date): Sale
{
    $s = new Sale([
        'business_id' => $c->business_id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'sale_date' => $date,
    ]);
    $s->setConnection('pgsql_migrate');
    $s->created_by = $u->id;
    $s->total = $total;
    $s->save();

    return $s;
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

    $summary = (new DashboardReportService(new KhataService(), new App\Services\StockService()))
        ->customerOutstanding($a->id);

    expect($summary->totalRupees)->toBe($expectedTotal)   // '1628.50'
        ->and($summary->totalRupees)->toBe('1628.50');

    // Sorted highest-due first, and business b's customer is absent.
    expect($summary->customers)->toHaveCount(2);
    expect($summary->customers[0]->name)->toBe('Ramesh');
    expect(collect($summary->customers)->pluck('name'))->not->toContain('Other Shop Customer');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php`
Expected: FAIL — class `DashboardReportService` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// app/Services/DashboardReportService.php

namespace App\Services;

use App\Models\Customer;
use App\Reports\CustomerDue;
use App\Reports\OutstandingSummary;

/**
 * Read-only aggregation behind the owner dashboard (Phase 0). Every method is
 * scoped explicitly by $businessId so it is correct with or without an ambient
 * tenant scope; the controller additionally runs it inside a tenant-pinned
 * transaction (RLS), so scoping is enforced twice — defense in depth.
 *
 * All money is bcmath decimal strings, never floats, matching KhataService.
 */
class DashboardReportService
{
    public function __construct(
        private readonly KhataService $khata,
        private readonly StockService $stock,
    ) {}

    /**
     * Total and per-customer outstanding, reproducing KhataService's identity
     * (opening + Σ sales − Σ payments) as one query — no per-customer loop. The
     * total equals Σ KhataService::outstandingFor by construction; the service
     * test asserts exactly that.
     */
    public function customerOutstanding(string $businessId): OutstandingSummary
    {
        $rows = Customer::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->selectRaw('name, village, (
                opening_balance
                + coalesce((select sum(s.total) from sales s where s.customer_id = customers.id), 0)
                - coalesce((select sum(p.amount) from payments p where p.customer_id = customers.id), 0)
            )::text as outstanding')
            ->get();

        $customers = $rows
            ->map(fn ($r) => new CustomerDue($r->name, $r->village, bcadd($r->outstanding, '0', 2)))
            ->sortByDesc(fn (CustomerDue $c) => (float) $c->outstandingRupees)
            ->values()
            ->all();

        $total = array_reduce(
            $customers,
            fn (string $carry, CustomerDue $c) => bcadd($carry, $c->outstandingRupees, 2),
            '0.00',
        );

        return new OutstandingSummary($total, $customers);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DashboardReportService.php tests/Unit/DashboardReportServiceTest.php
git commit -m "feat: add dashboard customer-outstanding aggregation with khata parity"
```

---

## Task 5: Sales figures + monthly sales trend

**Files:**
- Modify: `app/Services/DashboardReportService.php`
- Test: `tests/Unit/DashboardReportServiceTest.php`

- [ ] **Step 1: Write the failing test (append to the file)**

```php
it('sums sales for today and the selected month, and builds a 12-row sales trend', function () {
    Illuminate\Support\Carbon::setTestNow('2026-07-22');

    $a = Business::factory()->create();
    $u = User::factory()->create();
    $c = dashCustomer($a, 'Ramesh');

    dashSale($c, $u, '100.00', '2026-07-22');  // today
    dashSale($c, $u, '40.00', '2026-07-05');   // this month, not today
    dashSale($c, $u, '25.00', '2026-05-09');   // May
    dashSale($c, $u, '9.00', '2025-07-01');    // different year — excluded

    $svc = new DashboardReportService(new KhataService(), new App\Services\StockService());

    expect($svc->salesToday($a->id))->toBe('100.00');
    expect($svc->salesForMonth($a->id, 2026, 7))->toBe('140.00');

    $trend = $svc->salesTrend($a->id, 2026);      // list<string>, index 0 = Jan
    expect($trend)->toHaveCount(12);
    expect($trend[6])->toBe('140.00');            // July
    expect($trend[4])->toBe('25.00');             // May
    expect($trend[0])->toBe('0.00');              // January, empty

    Illuminate\Support\Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php --filter="sales trend"`
Expected: FAIL — method `salesToday` not defined.

- [ ] **Step 3: Add the methods**

Add `use App\Models\Sale;` and `use Illuminate\Support\Carbon;` at the top, then:

```php
    public function salesToday(string $businessId): string
    {
        $sum = (string) Sale::query()
            ->where('business_id', $businessId)
            ->whereDate('sale_date', Carbon::now()->toDateString())
            ->selectRaw('coalesce(sum(total), 0)::text as agg')
            ->value('agg');

        return bcadd($sum, '0', 2);
    }

    public function salesForMonth(string $businessId, int $year, int $month): string
    {
        $sum = (string) Sale::query()
            ->where('business_id', $businessId)
            ->whereRaw('extract(year from sale_date) = ?', [$year])
            ->whereRaw('extract(month from sale_date) = ?', [$month])
            ->selectRaw('coalesce(sum(total), 0)::text as agg')
            ->value('agg');

        return bcadd($sum, '0', 2);
    }

    /** @return list<string> 12 decimal strings, index 0 = January. */
    public function salesTrend(string $businessId, int $year): array
    {
        $byMonth = Sale::query()
            ->where('business_id', $businessId)
            ->whereRaw('extract(year from sale_date) = ?', [$year])
            ->selectRaw('extract(month from sale_date)::int as m, coalesce(sum(total), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return array_map(
            fn (int $m) => bcadd((string) ($byMonth[$m] ?? '0'), '0', 2),
            range(1, 12),
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php`
Expected: PASS (all in file).

- [ ] **Step 5: Commit**

```bash
git add app/Services/DashboardReportService.php tests/Unit/DashboardReportServiceTest.php
git commit -m "feat: add sales-today, sales-month and sales-trend aggregation"
```

---

## Task 6: Production figures + monthly production trend

**Files:**
- Modify: `app/Services/DashboardReportService.php`
- Test: `tests/Unit/DashboardReportServiceTest.php`

- [ ] **Step 1: Write the failing test (append)**

```php
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

it('sums production for the month and builds a 12-row kg trend', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();

    dashBatch($a, $u, '50.000', '2026-07-04');
    dashBatch($a, $u, '30.000', '2026-07-20');
    dashBatch($a, $u, '10.000', '2026-05-02');

    $svc = new DashboardReportService(new KhataService(), new App\Services\StockService());

    expect($svc->productionForMonth($a->id, 2026, 7))->toBe('80.000');

    $trend = $svc->productionTrend($a->id, 2026);
    expect($trend)->toHaveCount(12);
    expect($trend[6])->toBe('80.000');  // July
    expect($trend[4])->toBe('10.000');  // May
    expect($trend[0])->toBe('0.000');   // January
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php --filter="production"`
Expected: FAIL — method `productionForMonth` not defined.

- [ ] **Step 3: Add the methods**

Add `use App\Models\ProductionBatch;` at the top, then:

```php
    public function productionForMonth(string $businessId, int $year, int $month): string
    {
        $sum = (string) ProductionBatch::query()
            ->where('business_id', $businessId)
            ->whereRaw('extract(year from batch_date) = ?', [$year])
            ->whereRaw('extract(month from batch_date) = ?', [$month])
            ->selectRaw('coalesce(sum(output_kg), 0)::text as agg')
            ->value('agg');

        return bcadd($sum, '0', 3);
    }

    /** @return list<string> 12 scale-3 kg strings, index 0 = January. */
    public function productionTrend(string $businessId, int $year): array
    {
        $byMonth = ProductionBatch::query()
            ->where('business_id', $businessId)
            ->whereRaw('extract(year from batch_date) = ?', [$year])
            ->selectRaw('extract(month from batch_date)::int as m, coalesce(sum(output_kg), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return array_map(
            fn (int $m) => bcadd((string) ($byMonth[$m] ?? '0'), '0', 3),
            range(1, 12),
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DashboardReportService.php tests/Unit/DashboardReportServiceTest.php
git commit -m "feat: add production-month and production-trend aggregation"
```

---

## Task 7: Low-stock list + product-wise performance

**Files:**
- Modify: `app/Services/DashboardReportService.php`
- Test: `tests/Unit/DashboardReportServiceTest.php`

- [ ] **Step 1: Write the failing test (append)**

```php
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

    $svc = new DashboardReportService(new KhataService(), new App\Services\StockService());

    $low = $svc->lowStock($a->id);
    expect(collect($low)->pluck('name'))->toContain('Salt')->not->toContain('Besan');

    $perf = $svc->productPerformance($a->id, 2026);
    expect($perf[0]->name)->toBe('Sev 1kg');
    expect($perf[0]->qtySold)->toBe(10);
    expect($perf[0]->salesRupees)->toBe('1000.00');
    expect($perf[0]->estCostRupees)->toBe('930.00');
    expect($perf[0]->estProfitRupees)->toBe('70.00');
    expect($perf[0]->marginPercent)->toBe('7.0');
});
```

Add these two helpers at the top of the test file (once):

```php
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

function saleLine(App\Models\Sale $s, App\Models\ProductPack $pack, int $qty, string $rate): void
{
    $line = new App\Models\SaleLine([
        'business_id' => $s->business_id, 'sale_id' => $s->id,
        'product_pack_id' => $pack->id, 'qty' => $qty, 'rate' => $rate,
    ]);
    $line->setConnection('pgsql_migrate');
    $line->line_total = bcmul($rate, (string) $qty, 2);
    $line->save();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php --filter="reorder"`
Expected: FAIL — method `lowStock` not defined.

- [ ] **Step 3: Add the methods**

Add `use App\Models\RawMaterial;` and `use App\Reports\LowStockRow;` and `use App\Reports\ProductPerf;` at the top, then:

```php
    /**
     * Materials below their reorder level. Reuses StockService so the on-hand
     * and threshold logic stays in one place. Raw materials per tenant are few,
     * so a per-material check is fine (no N+1 concern at this cardinality).
     *
     * @return list<LowStockRow>
     */
    public function lowStock(string $businessId): array
    {
        return RawMaterial::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->get()
            ->filter(fn (RawMaterial $m) => $this->stock->belowReorder($m))
            ->map(fn (RawMaterial $m) => new LowStockRow(
                $m->name,
                $this->stock->onHandFor($m),
                bcadd((string) $m->reorder_level, '0', 3),
            ))
            ->values()
            ->all();
    }

    /**
     * Per product-pack sales for the year: qty, revenue, estimated cost
     * (qty × default_cost_price, treated as 0 when unpriced) and margin.
     * Ordered by revenue, highest first.
     *
     * @return list<ProductPerf>
     */
    public function productPerformance(string $businessId, int $year): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('sale_lines as sl')
            ->join('sales as s', 's.id', '=', 'sl.sale_id')
            ->join('product_packs as pp', 'pp.id', '=', 'sl.product_pack_id')
            ->join('products as prod', 'prod.id', '=', 'pp.product_id')
            ->join('pack_sizes as ps', 'ps.id', '=', 'pp.pack_size_id')
            ->where('sl.business_id', $businessId)
            ->whereRaw('extract(year from s.sale_date) = ?', [$year])
            ->groupBy('pp.id', 'prod.name_en', 'prod.name_hi', 'ps.label', 'pp.default_cost_price')
            ->selectRaw("
                coalesce(prod.name_en, prod.name_hi) || ' ' || ps.label as name,
                sum(sl.qty)::int as qty,
                sum(sl.line_total)::text as sales,
                sum(sl.qty * coalesce(pp.default_cost_price, 0))::text as est_cost
            ")
            ->get();

        return $rows
            ->map(function ($r) {
                $sales = bcadd($r->sales, '0', 2);
                $cost = bcadd($r->est_cost, '0', 2);
                $profit = bcsub($sales, $cost, 2);
                $margin = bccomp($sales, '0.00', 2) === 0
                    ? '0.0'
                    : bcadd(bcmul(bcdiv($profit, $sales, 4), '100', 2), '0', 1);

                return new ProductPerf($r->name, (int) $r->qty, $sales, $cost, $profit, $margin);
            })
            ->sortByDesc(fn (ProductPerf $p) => (float) $p->salesRupees)
            ->values()
            ->all();
    }
```

Add `bcsub`/`bcdiv` are PHP built-ins, no import needed.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DashboardReportService.php tests/Unit/DashboardReportServiceTest.php
git commit -m "feat: add low-stock and product-performance aggregation"
```

---

## Task 8: `forMonth()` — assemble the full `DashboardReport`

**Files:**
- Modify: `app/Services/DashboardReportService.php`
- Test: `tests/Unit/DashboardReportServiceTest.php`

- [ ] **Step 1: Write the failing test (append)**

```php
it('assembles a full report, with highest-selling/profit and an empty-shop case', function () {
    Illuminate\Support\Carbon::setTestNow('2026-07-22');

    // Empty shop first: everything zero, nothing crashes.
    $empty = Business::factory()->create();
    $svc = new DashboardReportService(new KhataService(), new App\Services\StockService());
    $report = $svc->forMonth($empty->id, App\Reports\ReportPeriod::fromInput(2026, 7));

    expect($report->salesMonthRupees)->toBe('0.00');
    expect($report->outstanding->totalRupees)->toBe('0.00');
    expect($report->lowStockCount)->toBe(0);
    expect($report->highestSellingName)->toBeNull();
    expect($report->trend)->toHaveCount(12);

    Illuminate\Support\Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php --filter="assembles"`
Expected: FAIL — method `forMonth` not defined.

- [ ] **Step 3: Add the method**

Add `use App\Reports\DashboardReport;`, `use App\Reports\ReportPeriod;`, `use App\Reports\TrendRow;` at the top, then:

```php
    public function forMonth(string $businessId, ReportPeriod $period): DashboardReport
    {
        $salesTrend = $this->salesTrend($businessId, $period->year);
        $prodTrend = $this->productionTrend($businessId, $period->year);
        $trend = array_map(
            fn (int $m) => new TrendRow($m, $salesTrend[$m - 1], $prodTrend[$m - 1]),
            range(1, 12),
        );

        $performance = $this->productPerformance($businessId, $period->year);
        $lowStock = $this->lowStock($businessId);

        $highestSelling = collect($performance)
            ->sortByDesc(fn (ProductPerf $p) => $p->qtySold)->first();
        $highestProfit = collect($performance)
            ->sortByDesc(fn (ProductPerf $p) => (float) $p->estProfitRupees)->first();

        return new DashboardReport(
            period: $period,
            salesTodayRupees: $this->salesToday($businessId),
            salesMonthRupees: $this->salesForMonth($businessId, $period->year, $period->month),
            outstanding: $this->customerOutstanding($businessId),
            productionMonthKg: $this->productionForMonth($businessId, $period->year, $period->month),
            lowStock: $lowStock,
            lowStockCount: count($lowStock),
            productPerformance: $performance,
            highestSellingName: $highestSelling?->name,
            highestProfitName: $highestProfit?->name,
            trend: $trend,
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php`
Expected: PASS (whole file green).

- [ ] **Step 5: Commit**

```bash
git add app/Services/DashboardReportService.php tests/Unit/DashboardReportServiceTest.php
git commit -m "feat: assemble full DashboardReport in forMonth"
```

---

## Task 9: `ResolvesOwnedTenant` trait

Extracts the owner-resolution + tenant-pinning helpers (currently duplicated in `BillingController` and `OnboardingController`) so the new controller reuses them. **Do not modify the existing controllers** — just create the trait and use it in the new one.

**Files:**
- Create: `app/Http/Controllers/Concerns/ResolvesOwnedTenant.php`

- [ ] **Step 1: Create the trait**

```php
<?php
// app/Http/Controllers/Concerns/ResolvesOwnedTenant.php

namespace App\Http\Controllers\Concerns;

use App\Models\Membership;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only web controllers share this: resolve the caller's OWNED business
 * (never one supplied by the request), then run work with that tenant pinned
 * (RLS GUC + app-level scope + owner role) in a transaction. Mirrors the
 * BillingController/OnboardingController pattern exactly.
 */
trait ResolvesOwnedTenant
{
    /**
     * The id of a business this user owns. An explicit $requested scopes to it,
     * but only if owned — so a guessed id cannot open someone else's data. With
     * none, the sole owned business is used. Null when nothing matches.
     */
    protected function ownedBusinessId(?string $requested): ?string
    {
        return TenantContext::forUser((int) auth()->id(), function () use ($requested) {
            $query = Membership::where('user_id', auth()->id())->where('role', 'owner');

            if ($requested !== null) {
                $query->where('business_id', $requested);
            }

            return $query->value('business_id');
        });
    }

    /**
     * Run $work with the tenant pinned, in one transaction.
     *
     * @template T
     * @param  callable(): T  $work
     * @return T
     */
    protected function runInTenant(string $businessId, callable $work): mixed
    {
        return DB::transaction(function () use ($businessId, $work) {
            TenantContext::switchTo($businessId);
            app()->bind('tenant.id', fn () => $businessId);
            app()->bind('tenant.user_id', fn () => (int) auth()->id());
            app()->bind('tenant.role', fn () => 'owner');

            return $work();
        });
    }
}
```

- [ ] **Step 2: Verify it loads**

Run: `php -r "require 'vendor/autoload.php'; echo trait_exists('App\Http\Controllers\Concerns\ResolvesOwnedTenant') ? 'ok' : 'missing';"`
Expected: prints `ok`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Concerns/ResolvesOwnedTenant.php
git commit -m "feat: add ResolvesOwnedTenant controller trait"
```

---

## Task 10: `ReportController` + route + access tests

**Files:**
- Create: `app/Http/Controllers/Web/ReportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Web/ReportsDashboardTest.php`

- [ ] **Step 1: Write the failing test**

Mirrors `tests/Feature/Web/BillingPageTest.php` conventions (session `actingAs`, owner seeded via `pgsql_migrate`).

```php
<?php
// tests/Feature/Web/ReportsDashboardTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Str;

/** @return array{0: User, 1: Business} */
function reportsOwner(): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => 'owner',
    ]);

    return [$user, $business];
}

describe('access', function () {
    it('redirects a guest to login', function () {
        $this->get('/reports/dashboard')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $this->actingAs(User::factory()->create())
            ->get('/reports/dashboard')->assertRedirect(route('app'));
    });

    it('refuses an owner asking for a business they do not own', function () {
        [$owner] = reportsOwner();
        [, $other] = reportsOwner();

        $this->actingAs($owner)
            ->get('/reports/dashboard?business=' . $other->id)
            ->assertRedirect(route('app'));
    });
});

describe('render', function () {
    it('shows the dashboard heading and the total-due figure for the owner', function () {
        [$owner, $business] = reportsOwner();
        Customer::on('pgsql_migrate')->create([
            'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
            'name' => 'Ramesh', 'opening_balance' => '1500.00',
        ]);

        $this->actingAs($owner)
            ->get('/reports/dashboard')
            ->assertOk()
            ->assertSee(__('reports.heading'))
            ->assertSee(__('reports.customer_outstanding'))
            ->assertSee('₹1,500.00');   // Inr-formatted outstanding
    });

    it('clamps an out-of-range month without erroring', function () {
        [$owner] = reportsOwner();

        $this->actingAs($owner)
            ->get('/reports/dashboard?year=2026&month=99')
            ->assertOk();
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Web/ReportsDashboardTest.php`
Expected: FAIL — route `/reports/dashboard` not defined (404/RouteNotFoundException).

- [ ] **Step 3: Create the controller**

```php
<?php
// app/Http/Controllers/Web/ReportController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Reports\ReportPeriod;
use App\Services\DashboardReportService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The owner management dashboard (Phase 0 spec): Blade, online-only, owner-only.
 * Read-only, so deliberately OUTSIDE any write plan-gate — a lapsed owner may
 * still view their reports, exactly like the billing page stays reachable.
 *
 * Owner resolution and tenant pinning come from ResolvesOwnedTenant, the same
 * pattern the billing and onboarding controllers use.
 */
class ReportController extends Controller
{
    use ResolvesOwnedTenant;

    public function __construct(private readonly DashboardReportService $reports) {}

    public function show(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));

        if ($businessId === null) {
            return redirect()->route('app');
        }

        $period = ReportPeriod::fromInput(
            $request->integer('year') ?: null,
            $request->integer('month') ?: null,
        );

        $report = $this->runInTenant(
            $businessId,
            fn () => $this->reports->forMonth($businessId, $period),
        );

        return view('reports.dashboard', [
            'report' => $report,
            'businessId' => $businessId,
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the import near the other `Web\` imports:

```php
use App\Http\Controllers\Web\ReportController;
```

Then add the route inside the existing `Route::middleware('auth')->group(...)` block (the one that also holds `billing`), right after the billing routes:

```php
    /*
     | Owner management dashboard (Phase 0) — Blade, online-only, owner-only.
     | Read-only, so intentionally NOT behind the plan gate: a lapsed owner may
     | still read their reports, like the billing page stays reachable.
     */
    Route::get('reports/dashboard', [ReportController::class, 'show'])->name('reports.dashboard');
```

- [ ] **Step 5: Run test to verify it fails differently**

Run: `./vendor/bin/pest tests/Feature/Web/ReportsDashboardTest.php`
Expected: access tests PASS; render tests FAIL — view `reports.dashboard` and `reports` lang file not found yet. (That is expected; the next two tasks add them.)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Web/ReportController.php routes/web.php tests/Feature/Web/ReportsDashboardTest.php
git commit -m "feat: add reports dashboard route and controller (access tests)"
```

---

## Task 11: Language files

**Files:**
- Create: `lang/en/reports.php`, `lang/hi/reports.php`

- [ ] **Step 1: Create the English file**

```php
<?php
// lang/en/reports.php

return [
    'title' => 'Management dashboard',
    'heading' => 'Management dashboard',
    'back_to_app' => 'Back to app',
    'report_date' => 'Report date',
    'period' => 'Period',
    'view' => 'View',

    // Tiles
    'sales_today' => 'Total sales today',
    'sales_month' => 'Total sales (this month)',
    'customer_outstanding' => 'Customer outstanding',
    'production_month' => 'Production (this month)',
    'stock_low_alerts' => 'Low stock alerts (items)',

    // Key insights
    'key_insights' => 'Key insights',
    'highest_selling_product' => 'Highest selling product',
    'highest_profit_product' => 'Highest profit product',

    // Low stock
    'low_stock' => 'Low stock alert',
    'material' => 'Material',
    'on_hand' => 'On hand',
    'reorder' => 'Reorder',

    // Product performance
    'product_performance' => 'Product-wise performance (this year)',
    'product' => 'Product',
    'qty_sold' => 'Qty sold',
    'sales' => 'Sales',
    'est_cost' => 'Est. cost',
    'est_profit' => 'Est. profit',
    'margin' => 'Margin %',

    // Trend
    'monthly_trend' => 'Monthly trend (this year)',
    'month' => 'Month',
    'production' => 'Production (kg)',
    'monthly_sales_chart' => 'Monthly sales',
    'monthly_production_chart' => 'Monthly production',

    // Empty states
    'no_customers' => 'No customers yet.',
    'no_products' => 'No sales recorded this year yet.',
    'no_low_stock' => 'All materials are above their reorder level.',
];
```

- [ ] **Step 2: Create the Hindi file**

```php
<?php
// lang/hi/reports.php

return [
    'title' => 'प्रबंधन डैशबोर्ड',
    'heading' => 'प्रबंधन डैशबोर्ड',
    'back_to_app' => 'ऐप पर वापस',
    'report_date' => 'रिपोर्ट तिथि',
    'period' => 'अवधि',
    'view' => 'देखें',

    'sales_today' => 'आज की कुल बिक्री',
    'sales_month' => 'इस महीने की कुल बिक्री',
    'customer_outstanding' => 'ग्राहक बकाया',
    'production_month' => 'उत्पादन (इस महीने)',
    'stock_low_alerts' => 'कम स्टॉक चेतावनी (वस्तुएँ)',

    'key_insights' => 'मुख्य जानकारी',
    'highest_selling_product' => 'सबसे ज़्यादा बिकने वाला उत्पाद',
    'highest_profit_product' => 'सबसे ज़्यादा मुनाफ़े वाला उत्पाद',

    'low_stock' => 'कम स्टॉक चेतावनी',
    'material' => 'सामग्री',
    'on_hand' => 'मौजूद',
    'reorder' => 'पुनःऑर्डर',

    'product_performance' => 'उत्पाद-वार प्रदर्शन (इस वर्ष)',
    'product' => 'उत्पाद',
    'qty_sold' => 'बिकी मात्रा',
    'sales' => 'बिक्री',
    'est_cost' => 'अनुमानित लागत',
    'est_profit' => 'अनुमानित मुनाफ़ा',
    'margin' => 'मार्जिन %',

    'monthly_trend' => 'मासिक रुझान (इस वर्ष)',
    'month' => 'महीना',
    'production' => 'उत्पादन (कि.ग्रा.)',
    'monthly_sales_chart' => 'मासिक बिक्री',
    'monthly_production_chart' => 'मासिक उत्पादन',

    'no_customers' => 'अभी कोई ग्राहक नहीं।',
    'no_products' => 'इस वर्ष अभी तक कोई बिक्री दर्ज नहीं हुई।',
    'no_low_stock' => 'सभी सामग्री पुनःऑर्डर स्तर से ऊपर हैं।',
];
```

- [ ] **Step 3: Verify the keys load**

Run: `php artisan tinker --execute="echo __('reports.heading');"`
Expected: prints `Management dashboard` (default locale is English per CLAUDE.md).

- [ ] **Step 4: Commit**

```bash
git add lang/en/reports.php lang/hi/reports.php
git commit -m "feat: add reports dashboard translations (en, hi)"
```

---

## Task 12: `SvgBarChart` view component

**Files:**
- Create: `app/View/Components/SvgBarChart.php`, `resources/views/components/svg-bar-chart.blade.php`
- Test: `tests/Unit/SvgBarChartTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/SvgBarChartTest.php

use App\View\Components\SvgBarChart;

it('produces one bar per value with heights scaled to the max', function () {
    $c = new SvgBarChart(
        values: [0, 5, 10],
        labels: ['Jan', 'Feb', 'Mar'],
        title: 'Test',
    );

    expect($c->bars())->toHaveCount(3);
    // Tallest value gets the full bar height; zero gets a zero-height bar.
    expect($c->bars()[2]['heightPct'])->toBe(100.0);
    expect($c->bars()[0]['heightPct'])->toBe(0.0);
    expect($c->bars()[1]['heightPct'])->toBe(50.0);
});

it('handles an all-zero series without dividing by zero', function () {
    $c = new SvgBarChart(values: [0, 0, 0], labels: ['a', 'b', 'c'], title: 'Zero');

    expect(collect($c->bars())->pluck('heightPct')->all())->toBe([0.0, 0.0, 0.0]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/SvgBarChartTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the component class**

```php
<?php
// app/View/Components/SvgBarChart.php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * A tiny inline-SVG bar chart, rendered server-side — no JS/chart library, so it
 * works in a printed report, needs no assets and trips no CSP. Bar heights are
 * a percentage of the series max; an all-zero series renders flat, never a
 * division by zero.
 */
class SvgBarChart extends Component
{
    /**
     * @param list<int|float|string> $values
     * @param list<string>           $labels
     */
    public function __construct(
        public array $values,
        public array $labels,
        public string $title,
    ) {}

    /** @return list<array{label: string, heightPct: float}> */
    public function bars(): array
    {
        $nums = array_map('floatval', $this->values);
        $max = max($nums);

        return array_map(
            fn (int $i) => [
                'label' => $this->labels[$i] ?? '',
                'heightPct' => $max > 0 ? round($nums[$i] / $max * 100, 1) : 0.0,
            ],
            array_keys($nums),
        );
    }

    public function render(): View
    {
        return view('components.svg-bar-chart');
    }
}
```

- [ ] **Step 4: Create the Blade template**

```blade
{{-- resources/views/components/svg-bar-chart.blade.php --}}
<figure class="chart">
    <figcaption class="chart-title">{{ $title }}</figcaption>
    <svg viewBox="0 0 240 120" role="img" aria-label="{{ $title }}" class="w-full">
        @foreach ($this->bars() as $i => $bar)
            @php
                $barWidth = 240 / max(1, count($this->bars())) * 0.6;
                $gap = 240 / max(1, count($this->bars()));
                $x = $i * $gap + ($gap - $barWidth) / 2;
                $h = $bar['heightPct'] / 100 * 100; // usable height 100
                $y = 100 - $h;
            @endphp
            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barWidth }}" height="{{ $h }}"
                  fill="#4472C4"></rect>
            <text x="{{ $x + $barWidth / 2 }}" y="115" font-size="6"
                  text-anchor="middle" fill="#555">{{ $bar['label'] }}</text>
        @endforeach
    </svg>
</figure>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/SvgBarChartTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/View/Components/SvgBarChart.php resources/views/components/svg-bar-chart.blade.php tests/Unit/SvgBarChartTest.php
git commit -m "feat: add server-rendered SvgBarChart component"
```

---

## Task 13: The dashboard Blade view + partials

**Files:**
- Create: `resources/views/reports/dashboard.blade.php` and partials under `resources/views/reports/partials/`
- Test: extends `tests/Feature/Web/ReportsDashboardTest.php` (already written in Task 10 — its render tests go green here)

- [ ] **Step 1: Create the main view**

```blade
@extends('layouts.app')

@section('title', __('reports.title') . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('reports.heading') }}</h1>
        <a href="{{ route('app') }}" class="text-sm text-brand">{{ __('reports.back_to_app') }}</a>
    </header>

    {{-- Period picker: GET form, so a bookmark/reload keeps the chosen month. --}}
    <form method="GET" action="{{ route('reports.dashboard') }}" class="mb-4 flex flex-wrap items-end gap-2">
        <input type="hidden" name="business" value="{{ $businessId }}">
        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('reports.month') }}</span>
            <select name="month" class="field-input">
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" @selected($m === $report->period->month)>
                        {{ \Illuminate\Support\Carbon::create()->month($m)->translatedFormat('F') }}
                    </option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('reports.period') }}</span>
            <select name="year" class="field-input">
                @foreach (range((int) date('Y'), 2020) as $y)
                    <option value="{{ $y }}" @selected($y === $report->period->year)>{{ $y }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn-primary">{{ __('reports.view') }}</button>
    </form>

    @include('reports.partials.tiles')
    @include('reports.partials.insights')

    <div class="mt-4 grid gap-4 md:grid-cols-2">
        @include('reports.partials.charts')
        @include('reports.partials.trend')
    </div>

    @include('reports.partials.products')
</div>
@endsection
```

- [ ] **Step 2: Create `partials/tiles.blade.php`**

```blade
{{-- resources/views/reports/partials/tiles.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="grid grid-cols-2 gap-3 md:grid-cols-4">
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.sales_today') }}</p>
        <p class="tabular text-lg font-bold">{{ Inr::format($report->salesTodayRupees) }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.sales_month') }}</p>
        <p class="tabular text-lg font-bold">{{ Inr::format($report->salesMonthRupees) }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.customer_outstanding') }}</p>
        <p class="tabular text-lg font-bold text-danger">{{ Inr::format($report->outstanding->totalRupees) }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.production_month') }}</p>
        <p class="tabular text-lg font-bold">{{ rtrim(rtrim($report->productionMonthKg, '0'), '.') ?: '0' }} Kg</p>
    </div>
</div>
```

- [ ] **Step 3: Create `partials/insights.blade.php`**

```blade
{{-- resources/views/reports/partials/insights.blade.php --}}
<div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.highest_selling_product') }}</p>
        <p class="font-semibold">{{ $report->highestSellingName ?? '—' }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.highest_profit_product') }}</p>
        <p class="font-semibold text-success">{{ $report->highestProfitName ?? '—' }}</p>
    </div>
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.stock_low_alerts') }}</p>
        <p class="font-semibold text-danger">{{ $report->lowStockCount }}</p>
    </div>
</div>

<div class="card mt-4">
    <h2 class="mb-2 font-semibold">{{ __('reports.low_stock') }}</h2>
    @if ($report->lowStock === [])
        <p class="text-sm text-ink-muted">{{ __('reports.no_low_stock') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('reports.material') }}</th>
                    <th class="text-right">{{ __('reports.on_hand') }}</th>
                    <th class="text-right">{{ __('reports.reorder') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report->lowStock as $row)
                    <tr>
                        <td>{{ $row->name }}</td>
                        <td class="tabular text-right">{{ $row->onHand }}</td>
                        <td class="tabular text-right">{{ $row->reorder }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
```

- [ ] **Step 4: Create `partials/charts.blade.php`**

```blade
{{-- resources/views/reports/partials/charts.blade.php --}}
@php
    $months = collect(range(1, 12))
        ->map(fn ($m) => \Illuminate\Support\Carbon::create()->month($m)->translatedFormat('M'))
        ->all();
    $salesValues = collect($report->trend)->map(fn ($t) => $t->salesRupees)->all();
    $prodValues = collect($report->trend)->map(fn ($t) => $t->productionKg)->all();
@endphp
<div class="card space-y-4">
    <x-svg-bar-chart :values="$salesValues" :labels="$months" :title="__('reports.monthly_sales_chart')" />
    <x-svg-bar-chart :values="$prodValues" :labels="$months" :title="__('reports.monthly_production_chart')" />
</div>
```

- [ ] **Step 5: Create `partials/trend.blade.php`**

```blade
{{-- resources/views/reports/partials/trend.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="card overflow-x-auto">
    <h2 class="mb-2 font-semibold">{{ __('reports.monthly_trend') }}</h2>
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-ink-muted">
                <th>{{ __('reports.month') }}</th>
                <th class="text-right">{{ __('reports.sales') }}</th>
                <th class="text-right">{{ __('reports.production') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report->trend as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::create()->month($row->month)->translatedFormat('M') }}</td>
                    <td class="tabular text-right">{{ Inr::format($row->salesRupees) }}</td>
                    <td class="tabular text-right">{{ rtrim(rtrim($row->productionKg, '0'), '.') ?: '0' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
```

- [ ] **Step 6: Create `partials/products.blade.php`**

```blade
{{-- resources/views/reports/partials/products.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="card mt-4 overflow-x-auto">
    <h2 class="mb-2 font-semibold">{{ __('reports.product_performance') }}</h2>
    @if ($report->productPerformance === [])
        <p class="text-sm text-ink-muted">{{ __('reports.no_products') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('reports.product') }}</th>
                    <th class="text-right">{{ __('reports.qty_sold') }}</th>
                    <th class="text-right">{{ __('reports.sales') }}</th>
                    <th class="text-right">{{ __('reports.est_cost') }}</th>
                    <th class="text-right">{{ __('reports.est_profit') }}</th>
                    <th class="text-right">{{ __('reports.margin') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report->productPerformance as $row)
                    <tr>
                        <td>{{ $row->name }}</td>
                        <td class="tabular text-right">{{ $row->qtySold }}</td>
                        <td class="tabular text-right">{{ Inr::format($row->salesRupees) }}</td>
                        <td class="tabular text-right">{{ Inr::format($row->estCostRupees) }}</td>
                        <td class="tabular text-right">{{ Inr::format($row->estProfitRupees) }}</td>
                        <td class="tabular text-right">{{ $row->marginPercent }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
```

- [ ] **Step 7: Run the feature test to verify render tests pass**

Run: `./vendor/bin/pest tests/Feature/Web/ReportsDashboardTest.php`
Expected: PASS (all, including the render + clamp tests).

- [ ] **Step 8: Commit**

```bash
git add resources/views/reports/
git commit -m "feat: add reports dashboard Blade view and partials"
```

---

## Task 14: Owner nav link from the React Home screen

The dashboard is Blade (online-only), so this is a real link out of the SPA, mirroring the existing owner billing link.

**Files:**
- Modify: `resources/js/screens/Home.jsx`
- Modify: `resources/js/i18n.js`

- [ ] **Step 1: Add the label key to both locales in `resources/js/i18n.js`**

Find the English and Hindi dictionaries and add:

```js
// in the English (en) dictionary:
reports_dashboard: 'Dashboard',
// in the Hindi (hi) dictionary:
reports_dashboard: 'डैशबोर्ड',
```

- [ ] **Step 2: Add the link in `resources/js/screens/Home.jsx`**

Immediately after the existing owner billing `<a>` (the block guarded by `isOwner && !readOnly`), add a second owner-only link. Place it inside the same `isOwner && !readOnly` region:

```jsx
{isOwner && !readOnly && (
    <a
        href={tenantId ? `/reports/dashboard?business=${tenantId}` : '/reports/dashboard'}
        className="btn-secondary mt-2 w-full"
        data-testid="dashboard-link"
    >
        {t('reports_dashboard')}
    </a>
)}
```

- [ ] **Step 3: Build the front-end to confirm no syntax error**

Run: `npm run build`
Expected: build succeeds (no errors).

- [ ] **Step 4: Commit**

```bash
git add resources/js/screens/Home.jsx resources/js/i18n.js
git commit -m "feat: link owners to the management dashboard from Home"
```

---

## Task 15: Full-suite green + wrap-up

- [ ] **Step 1: Run the whole PHP suite**

Run: `./vendor/bin/pest`
Expected: all green, including the new `InrTest`, `ReportPeriodTest`, `DashboardReportServiceTest`, `SvgBarChartTest`, and `ReportsDashboardTest`.

- [ ] **Step 2: Manually verify the page (optional but recommended)**

Seed demo data and open the page as the Namkeen owner:

```bash
php artisan db:seed --class=DemoDataSeeder
php artisan serve
```

Log in as `owner@demo-namkeen-bhandar.test` / `password123`, then visit `/reports/dashboard`. Confirm tiles, low-stock (should list ~the seeded materials), product performance, trend table and the two charts render, and the month/year picker reloads the page.

- [ ] **Step 3: Update the UI backlog**

Add a `done` row (or a `feature` row) to `docs/ui-backlog.md` noting the Phase 0 dashboard shipped, referencing this plan and the spec.

- [ ] **Step 4: Final commit**

```bash
git add docs/ui-backlog.md
git commit -m "docs: log Phase 0 reporting dashboard in ui-backlog"
```

---

## Self-review notes (traceability to the spec)

- **Route/access** (spec §Architecture): Task 10 — owner-only via `ResolvesOwnedTenant`, `?business` never trusted, non-owner/guessed → redirect to `/app`, outside the write plan-gate.
- **DashboardReportService** (spec §Components): Tasks 4–8, one method per figure, set-based, explicit `business_id` scope + tenant-pinned in controller.
- **Khata parity invariant** (spec §Correctness): Task 4 asserts total == Σ `KhataService::outstandingFor` and tenant isolation; bcmath throughout; margin guards divide-by-zero (Task 7).
- **Charts** (spec §Charts): Task 12 inline SVG, no JS lib; Sales + Production only (net-profit deferred).
- **Deferred blocks hidden** (spec §Decisions): no expenses/supplier/cash/stock-value anywhere in the view — verified by the partial set in Task 13.
- **Period picker** (spec §Decisions): `ReportPeriod` (Task 2) + the GET form (Task 13), clamping tested (Tasks 2, 10).
- **Empty/error states** (spec §Error handling): empty-shop service test (Task 8), empty-state partials (Task 13), clamp test (Task 10).
- **Targeted refactor** (spec §7): Task 9 extracts `ResolvesOwnedTenant`; existing controllers left unchanged to avoid churn.
- **Testing** (spec §Testing): unit (Inr, ReportPeriod, service, SvgBarChart) + HTTP feature (access, render, isolation, clamp).
- **Navigation** (spec §Navigation): Task 14 owner-only link from Home, business passed through.
