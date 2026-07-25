# Owner Cash Flow — Phase 3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a **cash-flow** section to the owner dashboard — money actually collected (customer payments) minus money actually paid out (supplier payments + operating expenses), the monthly net, and a running cash position — so the owner can see the gap between the accrual P&L and real cash. Derived-only: **no new tables, no migration, no controller**.

**Architecture:** A new read-only, tenant-pinned `CashFlowService` (three month-grouped sum queries + a pre-year opening sum), a `CashFlowRow` value object, additive fields on `DashboardReport`, and one new Blade partial + a Cash Position tile on the existing `/reports/dashboard`. All money is bcmath decimal strings at scale 2 — never floats. Same "always recomputable" discipline as Phase 0/2b.

**Tech Stack:** PHP 8.3 / Laravel 11, PostgreSQL (RLS), Blade, Pest. Reuses `App\Support\Inr`, `App\View\Components\SvgGroupedBarChart`, `App\Reports\ReportPeriod`, and the existing `DashboardReportService` / `reports.dashboard` render chain.

**Spec:** `docs/superpowers/specs/2026-07-25-owner-cash-flow-phase-3-design.md`

---

## Before you start

- You are on `master` with Phase 0–2b shipped. **Create a feature branch:**

```bash
git checkout master && git pull
git checkout -b feat/cash-flow-phase-3
```

- Local services (Postgres/PgBouncer/Redis) must be running; if the suite cannot connect, ask the user to start them (WSL sudo — only the user can).
- Confirm a green baseline and **record the passing count** so you can prove nothing regressed at the end:

```bash
cd backend && php artisan test
```

### Conventions used throughout (read once)

- **App root is `backend/`.** All paths below are relative to `backend/`. Run all commands from there.
- **Test data is written on the privileged `pgsql_migrate` connection** to bypass RLS during setup; the service under test reads through the tenant pin (`inTenant()`). Reuse the existing helpers in `tests/Unit/DashboardReportServiceTest.php`: `inTenant()`, `dashCustomer()`, `dashSale()`, `dashPayment()`, `dashExpense()`. You will add one new helper, `dashSupplierPayment()`.
- **Money is a scale-2 decimal string** (`'1200.00'`), summed with `bcadd`/`bcsub`, compared with `bccomp`. Select Postgres sums `::text`. Never cast money to float.
- Service queries **always** `->where('business_id', $businessId)` explicitly (app-level scope) on top of FORCE'd RLS — defense in depth, never one layer alone.
- The dashboard service is resolved from the container in tests — `app(DashboardReportService::class)` — so the new `CashFlowService` constructor dependency autowires with no binding.

### Scope decisions locked from the spec (do not re-litigate)

- Cash-**in** = `payments.amount` only (a reversal is a row with a negated amount, so `Σ amount` self-nets; `payments` has no `archived_at`). **Not** sales.
- Cash-**out** = `supplier_payments.amount` + `expenses.amount`, both `whereNull('archived_at')`. **Not** purchases, **not** COGS.
- `mode` is **not** filtered (cash/UPI/bank all count as movement). `subscription_payments` are the tenant's SaaS bill to the platform — **excluded**.
- Running position = cumulative net cash since inception (no stored opening-cash figure — that would be a new table, out of scope). The UI labels it "money recorded, not a bank balance".

---

## File structure

**Create:**
- `app/Reports/CashFlowRow.php` — readonly VO, one per month.
- `app/Services/CashFlowService.php` — the three trends + opening position.
- `resources/views/reports/partials/cash.blade.php` — monthly table + net-cash chart.
- `tests/Unit/CashFlowServiceTest.php`

**Modify:**
- `app/Services/DashboardReportService.php` — inject `CashFlowService`, assemble the cash trend + month figures in `forMonth`.
- `app/Reports/DashboardReport.php` — new additive fields (nothing renamed).
- `resources/views/reports/partials/tiles.blade.php` — Cash Position tile.
- `resources/views/reports/dashboard.blade.php` — `@include` the cash partial.
- `lang/en/reports.php`, `lang/hi/reports.php` — cash-flow labels.
- `tests/Unit/DashboardReportServiceTest.php` — cash assembly test + `dashSupplierPayment()` helper + empty-shop zeros.
- `tests/Feature/Web/ReportsDashboardTest.php` — cash section renders.

---

## Task 1: `CashFlowRow` VO + `CashFlowService` + unit tests (TDD)

**Files:**
- Create: `app/Reports/CashFlowRow.php`, `app/Services/CashFlowService.php`, `tests/Unit/CashFlowServiceTest.php`

- [ ] **Step 1: Create the value object**

```php
<?php
// app/Reports/CashFlowRow.php
namespace App\Reports;

/**
 * One month of cash flow for the dashboard cash section (Phase 3).
 * All figures are scale-2 bcmath decimal strings; netCash and position may be
 * negative. position is running (seeded from CashFlowService::openingPosition),
 * so it is net-cash-since-inception, not a bank balance.
 */
final readonly class CashFlowRow
{
    public function __construct(
        public int $month,             // 1..12
        public string $cashInRupees,   // customer collections
        public string $cashOutRupees,  // supplier payments + operating expenses
        public string $netCashRupees,  // in − out (may be negative)
        public string $positionRupees, // running cumulative net (may be negative)
    ) {}
}
```

- [ ] **Step 2: Write the failing unit test**

```php
<?php
// tests/Unit/CashFlowServiceTest.php

use App\Models\Business;
use App\Models\User;
use App\Services\CashFlowService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Tenant-pinned run, as the controller does in prod. */
function inCashTenant(string $businessId, callable $fn): mixed
{
    return DB::transaction(function () use ($businessId, $fn) {
        TenantContext::switchTo($businessId);
        app()->bind('tenant.id', fn () => $businessId);

        return $fn();
    });
}

function cashCustomer(Business $b): App\Models\Customer
{
    return App\Models\Customer::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Cust', 'village' => 'V', 'opening_balance' => '0.00',
    ]);
}

function cashPayment(App\Models\Customer $c, User $u, string $amount, string $date): void
{
    $p = new App\Models\Payment([
        'business_id' => $c->business_id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'payment_date' => $date, 'amount' => $amount, 'mode' => 'cash',
    ]);
    $p->setConnection('pgsql_migrate');
    $p->created_by = $u->id;
    $p->save();
}

function cashSupplier(Business $b): App\Models\Supplier
{
    return App\Models\Supplier::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Supp', 'opening_balance' => '0.00',
    ]);
}

function cashSupplierPayment(App\Models\Supplier $s, User $u, string $amount, string $date, ?string $archivedAt = null): void
{
    $sp = new App\Models\SupplierPayment([
        'business_id' => $s->business_id, 'uuid' => (string) Str::uuid(),
        'supplier_id' => $s->id, 'payment_date' => $date, 'amount' => $amount, 'mode' => 'cash',
    ]);
    $sp->setConnection('pgsql_migrate');
    $sp->created_by = $u->id;
    $sp->archived_at = $archivedAt;
    $sp->save();
}

function cashExpense(Business $b, User $u, string $amount, string $date, ?string $archivedAt = null): void
{
    $e = new App\Models\Expense([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'category' => 'rent', 'amount' => $amount, 'spent_on' => $date,
    ]);
    $e->setConnection('pgsql_migrate');
    $e->created_by = $u->id;
    $e->archived_at = $archivedAt;
    $e->save();
}

it('sums cash-in from payments, netting a reversal, and isolates tenants', function () {
    $a = Business::factory()->create();
    $b = Business::factory()->create();   // must NOT leak in
    $u = User::factory()->create();

    $c = cashCustomer($a);
    cashPayment($c, $u, '1000.00', '2026-07-05');
    cashPayment($c, $u, '-200.00', '2026-07-06');   // a reversal (negated amount)
    cashPayment($c, $u, '500.00', '2026-05-02');    // May
    cashPayment(cashCustomer($b), $u, '9999.00', '2026-07-01'); // other tenant

    $in = inCashTenant($a->id, fn () => app(CashFlowService::class)->cashInTrend($a->id, 2026));

    expect($in)->toHaveCount(12);
    expect($in[6])->toBe('800.00');   // July: 1000 − 200
    expect($in[4])->toBe('500.00');   // May
    expect($in[0])->toBe('0.00');     // January
});

it('sums cash-out from supplier payments and expenses, excluding archived rows', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();

    $s = cashSupplier($a);
    cashSupplierPayment($s, $u, '700.00', '2026-07-10');
    cashSupplierPayment($s, $u, '300.00', '2026-07-11', archivedAt: '2026-07-12 00:00:00'); // archived → excluded
    cashExpense($a, $u, '250.00', '2026-07-01');
    cashExpense($a, $u, '999.00', '2026-07-02', archivedAt: '2026-07-03 00:00:00'); // archived → excluded

    [$supplierOut, $expenseOut] = inCashTenant($a->id, function () use ($a) {
        $svc = app(CashFlowService::class);

        return [$svc->supplierOutTrend($a->id, 2026), $svc->expenseOutTrend($a->id, 2026)];
    });

    expect($supplierOut[6])->toBe('700.00'); // archived 300 excluded
    expect($expenseOut[6])->toBe('250.00');  // archived 999 excluded
});

it('computes the opening position as cumulative net cash strictly before the year', function () {
    $a = Business::factory()->create();
    $u = User::factory()->create();

    $c = cashCustomer($a);
    $s = cashSupplier($a);
    // 2025 (prior year): in 5000, supplier 1000, expense 500 → net +3500.
    cashPayment($c, $u, '5000.00', '2025-11-01');
    cashSupplierPayment($s, $u, '1000.00', '2025-11-02');
    cashExpense($a, $u, '500.00', '2025-11-03');
    // 2026 events must NOT count toward the 2026 opening.
    cashPayment($c, $u, '8000.00', '2026-01-05');

    $opening = inCashTenant($a->id, fn () => app(CashFlowService::class)->openingPosition($a->id, 2026));

    expect($opening)->toBe('3500.00');
});
```

- [ ] **Step 3: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Unit/CashFlowServiceTest.php`
Expected: FAIL — class `App\Services\CashFlowService` not found.

- [ ] **Step 4: Create `CashFlowService`**

```php
<?php
// app/Services/CashFlowService.php

namespace App\Services;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\SupplierPayment;
use Illuminate\Support\Collection;

/**
 * Read-only cash-flow aggregation behind the owner dashboard (Phase 3).
 *
 * Like DashboardReportService, every method assumes it runs inside an
 * already-tenant-pinned transaction (RLS FORCE'd on the app connection). The
 * explicit ->where('business_id', ...) is the app-level layer of defense in
 * depth on top of that — never one layer alone.
 *
 * "Cash" here is money that actually changed hands: customer collections in,
 * supplier payments + operating expenses out. Sales and purchases are accrual
 * and deliberately excluded. All money is bcmath scale-2 decimal strings.
 *
 * The three trend queries mirror the shape of DashboardReportService's own
 * sales/production/expense trends by design — one self-contained, independently
 * testable service owns the whole cash picture, rather than reaching across.
 */
class CashFlowService
{
    /**
     * Cash in per month: Σ payments.amount grouped by month. A reversal is a row
     * with a negated amount, so the sum self-nets; payments have no soft-delete.
     *
     * @return list<string> 12 scale-2 strings, index 0 = January.
     */
    public function cashInTrend(string $businessId, int $year): array
    {
        $byMonth = Payment::query()
            ->where('business_id', $businessId)
            ->whereRaw('extract(year from payment_date) = ?', [$year])
            ->selectRaw('extract(month from payment_date)::int as m, coalesce(sum(amount), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return $this->twelve($byMonth);
    }

    /** @return list<string> 12 scale-2 strings; non-archived supplier payments. */
    public function supplierOutTrend(string $businessId, int $year): array
    {
        $byMonth = SupplierPayment::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->whereRaw('extract(year from payment_date) = ?', [$year])
            ->selectRaw('extract(month from payment_date)::int as m, coalesce(sum(amount), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return $this->twelve($byMonth);
    }

    /** @return list<string> 12 scale-2 strings; non-archived operating expenses. */
    public function expenseOutTrend(string $businessId, int $year): array
    {
        $byMonth = Expense::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->whereRaw('extract(year from spent_on) = ?', [$year])
            ->selectRaw('extract(month from spent_on)::int as m, coalesce(sum(amount), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return $this->twelve($byMonth);
    }

    /**
     * Cumulative net cash of every event STRICTLY BEFORE Jan 1 of $year:
     * Σ payments − Σ supplier_payments − Σ expenses (archived excluded on the
     * out side, matching the trends). Seeds the running position so the year
     * picker stays continuous with prior history; 0.00 for a tenant's first year.
     */
    public function openingPosition(string $businessId, int $year): string
    {
        $start = sprintf('%04d-01-01', $year);

        $in = (string) Payment::query()
            ->where('business_id', $businessId)
            ->whereRaw('payment_date < ?', [$start])
            ->selectRaw('coalesce(sum(amount), 0)::text as agg')->value('agg');

        $supplierOut = (string) SupplierPayment::query()
            ->where('business_id', $businessId)->whereNull('archived_at')
            ->whereRaw('payment_date < ?', [$start])
            ->selectRaw('coalesce(sum(amount), 0)::text as agg')->value('agg');

        $expenseOut = (string) Expense::query()
            ->where('business_id', $businessId)->whereNull('archived_at')
            ->whereRaw('spent_on < ?', [$start])
            ->selectRaw('coalesce(sum(amount), 0)::text as agg')->value('agg');

        return bcsub(bcsub(bcadd($in, '0', 2), $supplierOut, 2), $expenseOut, 2);
    }

    /**
     * Normalise a month=>agg map into 12 scale-2 strings, index 0 = January.
     *
     * @param  Collection<int, string>  $byMonth
     * @return list<string>
     */
    private function twelve(Collection $byMonth): array
    {
        return array_map(
            fn (int $m) => bcadd((string) ($byMonth[$m] ?? '0'), '0', 2),
            range(1, 12),
        );
    }
}
```

- [ ] **Step 5: Run it and watch it pass**

Run: `./vendor/bin/pest tests/Unit/CashFlowServiceTest.php`
Expected: PASS (3 passing).

- [ ] **Step 6: Commit**

```bash
git add app/Reports/CashFlowRow.php app/Services/CashFlowService.php tests/Unit/CashFlowServiceTest.php
git commit -m "feat: add CashFlowService and CashFlowRow (cash-in/out trends)"
```

---

## Task 2: Assemble cash flow in `DashboardReportService::forMonth`

Inject `CashFlowService`, walk the 12 months into a `list<CashFlowRow>` carrying a running position, and expose the selected-month figures on `DashboardReport`.

**Files:**
- Modify: `app/Reports/DashboardReport.php`, `app/Services/DashboardReportService.php`
- Test: `tests/Unit/DashboardReportServiceTest.php`

- [ ] **Step 1: Add the new fields to `DashboardReport`**

Append these to the constructor (after `supplierOutstanding`). They are additive — **nothing is renamed**, so every Phase 0–2b field keeps its name and position:

```php
        // Phase 3: cash flow — money actually collected vs. actually paid out.
        // Position is running net-cash-since-inception, NOT a bank balance
        // (there is no stored opening-cash figure). netCash/position may be < 0.
        public string $cashInMonthRupees,
        public string $supplierPaidMonthRupees,
        public string $expensePaidMonthRupees,
        public string $netCashMonthRupees,
        public string $cashPositionRupees,
        public array $cashTrend,   // list<CashFlowRow>, exactly 12 rows Jan..Dec
```

Also add to the class docblock:

```php
     * @param list<CashFlowRow>            $cashTrend  exactly 12 rows, Jan..Dec
```

- [ ] **Step 2: Add the `dashSupplierPayment()` helper (append near `dashExpense` in `tests/Unit/DashboardReportServiceTest.php`)**

```php
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
```

> If `Supplier`'s fillable/columns differ (check `app/Models/Supplier.php`), adjust the `firstOrCreate` attributes to match — the point is one supplier to hang payments off.

- [ ] **Step 3: Write the failing test (append)**

```php
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
    // The selected-month headline equals that month's trow position — one walk.
    expect($report->cashTrend[6]->positionRupees)->toBe($report->cashPositionRupees);

    Illuminate\Support\Carbon::setTestNow();
});
```

- [ ] **Step 4: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php --filter="cash trend"`
Expected: FAIL — `DashboardReport` constructor argument count (new fields not supplied yet).

- [ ] **Step 5: Wire `forMonth`**

In `app/Services/DashboardReportService.php`:

1. Add the imports (near the other `use` lines):

```php
use App\Reports\CashFlowRow;
```

2. Add `CashFlowService` to the constructor:

```php
    public function __construct(
        private readonly StockService $stock,
        private readonly PurchaseService $purchases,
        private readonly SupplierService $suppliers,
        private readonly CogsService $cogs,
        private readonly CashFlowService $cash,
    ) {}
```

3. In `forMonth`, after the existing P&L month figures (right before the `return new DashboardReport(...)`), assemble the cash trend:

```php
        // Phase 3: cash flow. Walk Jan..Dec once, carrying a running position
        // seeded from every event before this year, so the row position and the
        // selected-month headline come from the SAME walk and cannot drift.
        $cashIn = $this->cash->cashInTrend($businessId, $period->year);
        $supplierOut = $this->cash->supplierOutTrend($businessId, $period->year);
        $expenseOut = $this->cash->expenseOutTrend($businessId, $period->year);

        $position = $this->cash->openingPosition($businessId, $period->year);
        $cashTrend = [];
        foreach (range(1, 12) as $m) {
            $out = bcadd($supplierOut[$m - 1], $expenseOut[$m - 1], 2);
            $net = bcsub($cashIn[$m - 1], $out, 2);
            $position = bcadd($position, $net, 2);
            $cashTrend[] = new CashFlowRow($m, $cashIn[$m - 1], $out, $net, $position);
        }
        $cashMonth = $cashTrend[$period->month - 1];
```

4. Append the new arguments to the `return new DashboardReport(...)` call (after `supplierOutstanding:`):

```php
            cashInMonthRupees: $cashMonth->cashInRupees,
            supplierPaidMonthRupees: $supplierOut[$period->month - 1],
            expensePaidMonthRupees: $expenseOut[$period->month - 1],
            netCashMonthRupees: $cashMonth->netCashRupees,
            cashPositionRupees: $cashMonth->positionRupees,
            cashTrend: $cashTrend,
```

- [ ] **Step 6: Cover the empty shop**

Find the existing `it('assembles a full report, ...')` (the empty-business test) and add, after its current expectations:

```php
    expect($report->cashInMonthRupees)->toBe('0.00');
    expect($report->netCashMonthRupees)->toBe('0.00');
    expect($report->cashPositionRupees)->toBe('0.00');
    expect($report->cashTrend)->toHaveCount(12);
```

- [ ] **Step 7: Run the whole service test file**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php`
Expected: PASS (all, including the new cash test and the extended empty-shop test).

- [ ] **Step 8: Commit**

```bash
git add app/Reports/DashboardReport.php app/Services/DashboardReportService.php tests/Unit/DashboardReportServiceTest.php
git commit -m "feat: assemble cash-flow trend and month figures in DashboardReport"
```

---

## Task 3: Render — Cash Position tile, cash partial + chart, translations, feature test

**Files:**
- Create: `resources/views/reports/partials/cash.blade.php`
- Modify: `resources/views/reports/partials/tiles.blade.php`, `resources/views/reports/dashboard.blade.php`, `lang/en/reports.php`, `lang/hi/reports.php`
- Test: `tests/Feature/Web/ReportsDashboardTest.php`

- [ ] **Step 1: Add the English translations**

In `lang/en/reports.php`, add a cash-flow block (e.g. after the P&L keys):

```php
    // Cash flow (Phase 3)
    'cash_flow' => 'Cash flow (this year)',
    'cash_position' => 'Cash position',
    'cash_position_hint' => 'Recorded in VyaparBook — not a bank balance',
    'cash_in' => 'Cash in',
    'cash_out' => 'Cash out',
    'net_cash' => 'Net cash',
    'net_cash_month' => 'Net cash (this month)',
    'monthly_net_cash_chart' => 'Monthly net cash',
    'cash_flow_caption' => 'Cash actually collected (customer payments) minus cash actually paid out (suppliers + expenses). Credit sales and unpaid purchases are not counted until money changes hands.',
```

- [ ] **Step 2: Add the Hindi translations**

In `lang/hi/reports.php`, add the matching keys:

```php
    // Cash flow (Phase 3)
    'cash_flow' => 'नकदी प्रवाह (इस वर्ष)',
    'cash_position' => 'नकद स्थिति',
    'cash_position_hint' => 'VyaparBook में दर्ज — बैंक बैलेंस नहीं',
    'cash_in' => 'नकद आया',
    'cash_out' => 'नकद गया',
    'net_cash' => 'शुद्ध नकदी',
    'net_cash_month' => 'शुद्ध नकदी (इस महीने)',
    'monthly_net_cash_chart' => 'मासिक शुद्ध नकदी',
    'cash_flow_caption' => 'वास्तव में मिली नकदी (ग्राहक भुगतान) घटा वास्तव में चुकाई नकदी (आपूर्तिकर्ता + खर्च)। उधार बिक्री और बकाया खरीद तब तक नहीं गिनी जाती जब तक पैसा हाथ नहीं बदलता।',
```

- [ ] **Step 3: Add the Cash Position tile**

In `resources/views/reports/partials/tiles.blade.php`, change the grid to fit a sixth tile and append the tile. Change the opening `<div>`:

```blade
<div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
```

and add, as the last tile before the closing `</div>`:

```blade
    {{-- Cash position: running net cash recorded, not a bank balance
         (reports.cash_position_hint). Net-cash-this-month sub-label goes red
         when the month spent more than it collected, like the Net Profit cell. --}}
    <div class="card">
        <p class="text-sm text-ink-muted">{{ __('reports.cash_position') }}</p>
        <p class="tabular text-lg font-bold {{ bccomp($report->cashPositionRupees, '0.00', 2) < 0 ? 'text-danger' : '' }}">{{ Inr::format($report->cashPositionRupees) }}</p>
        <p class="text-xs {{ bccomp($report->netCashMonthRupees, '0.00', 2) < 0 ? 'text-danger' : 'text-ink-muted' }}">
            {{ __('reports.net_cash_month') }}: {{ Inr::format($report->netCashMonthRupees) }}
        </p>
    </div>
```

- [ ] **Step 4: Create the cash partial (monthly table + net-cash chart)**

```blade
{{-- resources/views/reports/partials/cash.blade.php --}}
@php
    use App\Support\Inr;
    $months = collect(range(1, 12))
        ->map(fn ($m) => \Illuminate\Support\Carbon::create()->month($m)->translatedFormat('M'))
        ->all();
    $netCashSeries = [
        ['label' => __('reports.net_cash'), 'color' => '#0891b2',
         'values' => collect($report->cashTrend)->map(fn ($r) => $r->netCashRupees)->all()],
    ];
@endphp
<div class="card mt-4">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="font-semibold">{{ __('reports.cash_flow') }}</h2>
    </div>
    <p class="mb-3 text-xs text-ink-muted">{{ __('reports.cash_flow_caption') }}</p>

    <div class="grid gap-4 md:grid-cols-2">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-ink-muted">
                        <th>{{ __('reports.month') }}</th>
                        <th class="text-right">{{ __('reports.cash_in') }}</th>
                        <th class="text-right">{{ __('reports.cash_out') }}</th>
                        <th class="text-right">{{ __('reports.net_cash') }}</th>
                        <th class="text-right">{{ __('reports.cash_position') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report->cashTrend as $row)
                        <tr>
                            <td>{{ $months[$row->month - 1] }}</td>
                            <td class="tabular text-right">{{ Inr::format($row->cashInRupees) }}</td>
                            <td class="tabular text-right">{{ Inr::format($row->cashOutRupees) }}</td>
                            <td class="tabular text-right {{ bccomp($row->netCashRupees, '0.00', 2) < 0 ? 'text-danger' : '' }}">{{ Inr::format($row->netCashRupees) }}</td>
                            <td class="tabular text-right {{ bccomp($row->positionRupees, '0.00', 2) < 0 ? 'text-danger' : '' }}">{{ Inr::format($row->positionRupees) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div>
            <x-svg-grouped-bar-chart :series="$netCashSeries" :labels="$months"
                                     :title="__('reports.monthly_net_cash_chart')" unit="inr" />
        </div>
    </div>
</div>
```

> `SvgGroupedBarChart` already draws negative values hanging below the zero line in red, so cash-negative months render correctly with no extra work.

- [ ] **Step 5: Include the partial on the dashboard**

In `resources/views/reports/dashboard.blade.php`, add the cash partial after the suppliers partial and before the charts/trend grid:

```blade
    @include('reports.partials.suppliers')
    @include('reports.partials.cash')
```

- [ ] **Step 6: Extend the dashboard render test**

In `tests/Feature/Web/ReportsDashboardTest.php`, extend the main render test to seed a payment, a supplier payment, and an expense in the current month, then assert the cash section renders. Add to that test's setup (adjust helper names to whatever the file already uses for creating these — mirror the existing seeding style):

```php
        $customer = /* the customer already seeded in this test, or seed one */;
        App\Models\Payment::on('pgsql_migrate')->create([
            'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id, 'payment_date' => now()->format('Y-m-d'),
            'amount' => '2000.00', 'mode' => 'cash', 'created_by' => $owner->id,
        ]);
```

and assert:

```php
            ->assertSee(__('reports.cash_flow'))
            ->assertSee(__('reports.cash_position'))
            ->assertSee(__('reports.net_cash'))
            ->assertSee(__('reports.cash_position_hint'));
```

> Keep it to labels + the hint caption — do not assert exact rupee figures for cash unless you control every payment/expense in the fixture, to avoid a brittle string match. The unit tests already lock the arithmetic.

- [ ] **Step 7: Run the render tests**

Run: `php artisan view:clear && ./vendor/bin/pest tests/Feature/Web/ReportsDashboardTest.php`
Expected: PASS (all).

- [ ] **Step 8: Commit**

```bash
git add resources/views/reports/ lang/en/reports.php lang/hi/reports.php tests/Feature/Web/ReportsDashboardTest.php
git commit -m "feat: render cash-flow section, Cash Position tile and net-cash chart"
```

---

## Task 4: Full-suite green + wrap-up

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test`
Expected: all green — the baseline count from "Before you start" plus the new `CashFlowServiceTest` cases and the extended dashboard tests. No Phase 0–2b regressions (the `DashboardReport` change is purely additive).

- [ ] **Step 2: Manually verify (recommended)**

```bash
php artisan db:seed --class=DemoDataSeeder   # already-seeded is fine
php artisan serve
```
Log in as `owner@demo-namkeen-bhandar.test` / `password123`. Open `/reports/dashboard`: confirm the Cash Position tile (with the "not a bank balance" hint), the Cash flow table (In / Out / Net / Position), and the monthly net-cash chart. Record a supplier payment or an expense larger than the month's collections to see a cash-negative month render in the danger colour, and confirm the running position still reconciles down the column.

- [ ] **Step 3: Update the UI backlog**

In `docs/ui-backlog.md`, add an `F-03` row under Features noting the Phase 3 cash-flow section shipped (derived-only, no new tables; cash-in = collections, cash-out = supplier payments + expenses; running position labelled as recorded cash, not a bank balance), referencing this plan and the spec.

- [ ] **Step 4: Final commit**

```bash
git add docs/ui-backlog.md
git commit -m "docs: log Phase 3 cash flow in ui-backlog"
```

- [ ] **Step 5: Finish the branch**

Use the `finishing-a-development-branch` skill (merge to master or open a PR, per preference). Verification is the local `php artisan test` run from Step 1 — there is no CI on this repo.

---

## Self-review notes (traceability to the spec)

- **Derived-only, no new tables** (spec Decision 1): Task 1 — `CashFlowService` reads `payments`/`supplier_payments`/`expenses`; no migration, no controller.
- **Cash-in = collections, reversals self-net** (spec Decision 2): `cashInTrend` sums `payments.amount` with no archived filter; unit test seeds a negated reversal.
- **Cash-out = supplier payments + expenses, archived excluded** (spec Decision 3): `supplierOutTrend` / `expenseOutTrend` with `whereNull('archived_at')`; unit test seeds archived rows that must not count.
- **Instrument-agnostic; subscription payments excluded** (spec Decision 4): `mode` never filtered; `subscription_payments` never queried.
- **Running position = net-since-inception, not a bank balance** (spec Decision 5): `openingPosition` (pre-year cumulative) seeds the single walk in `forMonth`; the tile + table carry the `cash_position_hint` caption; positions may go negative and render as-is.
- **Own dashboard section, not a P&L trend column** (spec Decision 6): dedicated `partials/cash.blade.php` + Cash Position tile; the P&L trend table is untouched.
- **One walk, headline == trend row** (spec §Implementation): `cashPositionRupees` is `$cashTrend[month-1]->positionRupees`; asserted in Task 2 Step 3.
- **Edge cases** (spec §Error handling): empty shop → zeros (Task 2 Step 6); cash-negative month → danger colour (Task 2 unit test + Task 3 rendering); archived exclusion (Task 1 unit test); first year → opening 0.00 (Task 1 unit test).
- **Testing** (spec §Testing): unit (`CashFlowServiceTest` + the `DashboardReportService` cash assembly test) and feature (dashboard cash section renders); Phase 0–2b regression via the additive DTO.
```
