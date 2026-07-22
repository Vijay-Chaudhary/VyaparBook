# Owner Expenses & Net Profit — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an owner-only Operating-Expenses module (record/edit/delete expenses by category) and the P&L it unlocks on the reporting dashboard — Est. Gross Profit → minus Expenses → **Net Profit**, net margin %, a per-category breakdown, and monthly expenses + net-profit trends/charts.

**Architecture:** A new tenant-owned `expenses` table (RLS + `BelongsToTenant`, uuid PK, soft-delete, online-only — no sync columns). A Blade owner-tool `ExpenseController` (`ResolvesOwnedTenant`, tenant-pinned) for CRUD, and an extension of the existing `DashboardReportService` for expenses aggregation + net-profit math. All money is bcmath decimal strings at scale 2 — never floats.

**Tech Stack:** PHP 8.3 / Laravel 11, PostgreSQL (RLS), Blade, Pest. Reuses `App\Http\Controllers\Concerns\ResolvesOwnedTenant`, `App\Reports\ReportPeriod`, `App\Support\Inr`, `App\View\Components\SvgBarChart`.

**Spec:** `docs/superpowers/specs/2026-07-22-owner-expenses-net-profit-phase-1-design.md`

---

## Before you start

- You are already on `master` with Phase 0 shipped. **Create a feature branch:**

```bash
git checkout master && git pull
git checkout -b feat/expenses-net-profit-phase-1
```

- Local services (Postgres/PgBouncer/Redis) must be running; if the suite cannot connect, ask the user to start them (WSL sudo).
- Confirm a green baseline: `cd backend && php artisan test` (expect 474 passing).

### Conventions used throughout (read once)

- **App root is `backend/`.** All paths below are relative to `backend/`. Run all commands from there.
- **Test data is written on the privileged `pgsql_migrate` connection** to bypass RLS during setup; the service/controller under test read through the tenant pin. Reuse the `inTenant()` / `dashCustomer()` / `dashSale()` / `saleLine()` helpers already in `tests/Unit/DashboardReportServiceTest.php`.
- **Money is a scale-2 decimal string** (`'1200.00'`), summed with `bcadd`/`bcsub` and compared with `bccomp`. Select Postgres sums `::text`. Never cast money to float.
- Service queries **always** `->where('business_id', $businessId)` explicitly, matching Phase 0.
- **uuid primary keys** across tenant tables (see `customers` migration). `foreignUuid('business_id')`.
- `created_by` is **not** fillable — stamp it: `$model->created_by = app('tenant.user_id')` inside the tenant pin (matches `ProductionWriter`/`LedgerWriter`).

---

## File structure

**Create:**
- `database/migrations/2026_07_22_000001_create_expenses_table.php`
- `app/Models/Expense.php`
- `app/Expenses/ExpenseCategory.php` — canonical category keys + validation.
- `app/Reports/ExpenseCategoryTotal.php` — readonly VO for the dashboard breakdown.
- `app/Http/Controllers/Web/ExpenseController.php`
- `resources/views/expenses/index.blade.php` (+ `partials/form.blade.php`, `partials/list.blade.php`)
- `resources/views/reports/partials/pnl.blade.php`
- `lang/en/expenses.php`, `lang/hi/expenses.php`
- Tests: `tests/Unit/ExpenseCategoryTest.php`, `tests/Feature/Web/ExpensesTest.php`

**Modify:**
- `app/Services/DashboardReportService.php` — expenses aggregation + net profit.
- `app/Reports/DashboardReport.php`, `app/Reports/TrendRow.php` — new fields.
- `resources/views/reports/dashboard.blade.php`, `resources/views/reports/partials/{trend,charts}.blade.php`.
- `routes/web.php` — expenses routes + owner nav.
- `lang/en/reports.php`, `lang/hi/reports.php` — P&L / net-profit labels.
- `tests/Unit/DashboardReportServiceTest.php`, `tests/Feature/Web/ReportsDashboardTest.php`.

---

## Task 1: `expenses` table + `Expense` model + `ExpenseCategory`

**Files:**
- Create: `app/Expenses/ExpenseCategory.php`, `tests/Unit/ExpenseCategoryTest.php`
- Create: `database/migrations/2026_07_22_000001_create_expenses_table.php`
- Create: `app/Models/Expense.php`

- [ ] **Step 1: Write the failing test for `ExpenseCategory`**

```php
<?php
// tests/Unit/ExpenseCategoryTest.php

use App\Expenses\ExpenseCategory;

it('exposes the canonical category keys in order', function () {
    expect(ExpenseCategory::keys())->toBe([
        'rent', 'salaries', 'electricity', 'transport', 'maintenance', 'other',
    ]);
});

it('validates membership', function () {
    expect(ExpenseCategory::isValid('rent'))->toBeTrue();
    expect(ExpenseCategory::isValid('groceries'))->toBeFalse();
    expect(ExpenseCategory::isValid(''))->toBeFalse();
});

it('knows which categories require a note', function () {
    expect(ExpenseCategory::requiresNote('other'))->toBeTrue();
    expect(ExpenseCategory::requiresNote('rent'))->toBeFalse();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Unit/ExpenseCategoryTest.php`
Expected: FAIL — class `App\Expenses\ExpenseCategory` not found.

- [ ] **Step 3: Create `ExpenseCategory`**

```php
<?php
// app/Expenses/ExpenseCategory.php

namespace App\Expenses;

/**
 * Single source of truth for operating-expense categories. The migration's
 * check constraint, the request validator, the Blade dropdown and the dashboard
 * breakdown all read this list, so it is defined exactly once. Display labels
 * live in lang/{en,hi}/expenses.php, keyed by these slugs.
 *
 * Operating expenses only — never stock/raw-material purchases (those are Phase
 * 2 and would double-count against estimated product cost).
 */
final class ExpenseCategory
{
    /** @return list<string> canonical order, used everywhere the list renders. */
    public static function keys(): array
    {
        return ['rent', 'salaries', 'electricity', 'transport', 'maintenance', 'other'];
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** `other` is a catch-all, so a note is expected to say what it was. */
    public static function requiresNote(string $key): bool
    {
        return $key === 'other';
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/pest tests/Unit/ExpenseCategoryTest.php`
Expected: PASS (3 passing).

- [ ] **Step 5: Create the migration**

```php
<?php
// database/migrations/2026_07_22_000001_create_expenses_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            // Client/idempotency key: a retried create resolves to the same row.
            $table->uuid('uuid');
            // Operating-expense category; validated against App\Expenses\ExpenseCategory.
            $table->string('category', 20);
            // Money is decimal(12,2) across the app; bcmath scale-2 strings.
            $table->decimal('amount', 12, 2);
            // The day the expense belongs to — drives month/year grouping.
            $table->date('spent_on');
            $table->string('note', 255)->nullable();
            // users.id is a bigint ($table->id()); created_by matches every other
            // tenant table (sales/payments/production_batches all use foreignId).
            $table->foreignId('created_by')->constrained('users');
            // Soft-delete: edit/delete archives; archived rows excluded from reads.
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // (business_id, uuid) unique + leftmost business_id index.
            $table->unique(['business_id', 'uuid']);
            // Month/trend queries scan business_id + spent_on.
            $table->index(['business_id', 'spent_on']);
        });

        // Online-only Blade table: NO version/sync_seq — never enters offline sync.

        DB::connection('pgsql_migrate')->statement('ALTER TABLE expenses ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql_migrate')->statement('ALTER TABLE expenses FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql_migrate')->statement(<<<'SQL'
            CREATE POLICY expenses_isolation ON expenses
            USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
            WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('expenses');
    }
};
```

- [ ] **Step 6: Create the `Expense` model**

```php
<?php
// app/Models/Expense.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    // created_by is absent from $fillable: stamped from app('tenant.user_id'),
    // never taken from request input. Online-only, so no version/sync_seq traits.
    protected $fillable = ['business_id', 'uuid', 'category', 'amount', 'spent_on', 'note'];

    protected $casts = [
        'amount' => 'decimal:2',
        'spent_on' => 'date',
        'archived_at' => 'datetime',
    ];
}
```

- [ ] **Step 7: Migrate and verify the model loads**

Run: `php artisan migrate --database=pgsql_migrate 2>/dev/null; php artisan migrate`
(If the suite uses `migrate:fresh` per test run — as Phase 0 does — a plain `php artisan test` in later tasks will build the table. This step just confirms the migration is syntactically valid.)
Run: `php -r "require 'vendor/autoload.php'; echo class_exists('App\Models\Expense') ? 'ok' : 'missing';"`
Expected: prints `ok`.

- [ ] **Step 7b: Register the new table in the DPDP registries (REQUIRED)**

Any table with a `business_id` must be listed in two hardcoded registries, or you break tenant export and silently orphan rows on tenant erasure (DPDP, PRD §13):
- Add `'expenses'` to `app/Export/TenantExporter.php`'s `TABLES` const — a guard test (`tests/Feature/Export/TenantExportTest.php`) introspects `information_schema` and **fails** until every `business_id` table is listed.
- Add `'expenses'` to `app/Export/TenantEraser.php`'s `DELETE_ORDER` const, positioned like other leaf tenant tables (nothing FKs to `expenses`; it cascades on `business_id`).
- If `TenantEraseTest` seeds a row per tenant table and asserts erasure completeness, add an `expenses` row so this stays covered.

- [ ] **Step 8: Commit**

```bash
git add app/Expenses/ExpenseCategory.php tests/Unit/ExpenseCategoryTest.php \
        database/migrations/2026_07_22_000001_create_expenses_table.php app/Models/Expense.php \
        app/Export/TenantExporter.php app/Export/TenantEraser.php
git commit -m "feat: add expenses table, Expense model and ExpenseCategory"
```

---

## Task 2: Expenses aggregation in `DashboardReportService`

Add expense totals, by-category breakdown, and the 12-month expenses trend. These are pure reads; net-profit assembly is Task 3.

**Files:**
- Create: `app/Reports/ExpenseCategoryTotal.php`
- Modify: `app/Services/DashboardReportService.php`
- Test: `tests/Unit/DashboardReportServiceTest.php`

- [ ] **Step 1: Create the breakdown VO**

```php
<?php
// app/Reports/ExpenseCategoryTotal.php
namespace App\Reports;

final readonly class ExpenseCategoryTotal
{
    public function __construct(
        public string $category,      // ExpenseCategory key, e.g. 'rent'
        public string $amountRupees,  // scale-2 decimal string
    ) {}
}
```

- [ ] **Step 2: Write the failing test (append to `tests/Unit/DashboardReportServiceTest.php`)**

Add a helper near the other `dash*` helpers (top of file, after `dashBatch`):

```php
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
```

Append this test:

```php
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
        $svc = new DashboardReportService(new App\Services\StockService());

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
```

- [ ] **Step 3: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php --filter="breaks them down"`
Expected: FAIL — method `expensesForMonth` not defined.

- [ ] **Step 4: Add the methods to `DashboardReportService`**

Add imports at the top (near the other `use` lines):

```php
use App\Expenses\ExpenseCategory;
use App\Models\Expense;
use App\Reports\ExpenseCategoryTotal;
```

Add these methods (place them after `grossProfitTrend`):

```php
    public function expensesForMonth(string $businessId, int $year, int $month): string
    {
        $sum = (string) Expense::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->whereRaw('extract(year from spent_on) = ?', [$year])
            ->whereRaw('extract(month from spent_on) = ?', [$month])
            ->selectRaw('coalesce(sum(amount), 0)::text as agg')
            ->value('agg');

        return bcadd($sum, '0', 2);
    }

    /**
     * Operating expenses grouped by category for the month, ordered by the
     * canonical ExpenseCategory order, with zero categories omitted.
     *
     * @return list<ExpenseCategoryTotal>
     */
    public function expensesByCategory(string $businessId, int $year, int $month): array
    {
        $byCategory = Expense::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->whereRaw('extract(year from spent_on) = ?', [$year])
            ->whereRaw('extract(month from spent_on) = ?', [$month])
            ->groupBy('category')
            ->selectRaw('category, sum(amount)::text as agg')
            ->pluck('agg', 'category');

        $out = [];
        foreach (ExpenseCategory::keys() as $key) {
            if ($byCategory->has($key)) {
                $out[] = new ExpenseCategoryTotal($key, bcadd((string) $byCategory[$key], '0', 2));
            }
        }

        return $out;
    }

    /** @return list<string> 12 scale-2 decimal strings, index 0 = January. */
    public function expensesTrend(string $businessId, int $year): array
    {
        $byMonth = Expense::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->whereRaw('extract(year from spent_on) = ?', [$year])
            ->selectRaw('extract(month from spent_on)::int as m, coalesce(sum(amount), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return array_map(
            fn (int $m) => bcadd((string) ($byMonth[$m] ?? '0'), '0', 2),
            range(1, 12),
        );
    }
```

- [ ] **Step 5: Run it and watch it pass**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php`
Expected: PASS (all in file, including the new test).

- [ ] **Step 6: Commit**

```bash
git add app/Reports/ExpenseCategoryTotal.php app/Services/DashboardReportService.php tests/Unit/DashboardReportServiceTest.php
git commit -m "feat: add expenses total, by-category and trend aggregation"
```

---

## Task 3: Net Profit in `forMonth` (DTO extension + math)

Extend `TrendRow` and `DashboardReport`, then compute Net Profit (loss-aware) and net margin (zero-sales guarded) inside `forMonth`.

**Files:**
- Modify: `app/Reports/TrendRow.php`, `app/Reports/DashboardReport.php`, `app/Services/DashboardReportService.php`
- Test: `tests/Unit/DashboardReportServiceTest.php`

- [ ] **Step 1: Extend `TrendRow`**

```php
<?php
// app/Reports/TrendRow.php
namespace App\Reports;

final readonly class TrendRow
{
    public function __construct(
        public int $month,                // 1..12
        public string $salesRupees,
        public string $productionKg,      // scale-3 decimal string
        public string $grossProfitRupees, // estimated: sales − product cost
        public string $expensesRupees,    // operating expenses that month
        public string $netProfitRupees,   // gross − expenses (may be negative)
    ) {}
}
```

- [ ] **Step 2: Extend `DashboardReport`**

Add the new fields. Insert `expensesMonthRupees`, `netProfitMonthRupees`, `netProfitMarginPercent` right after `estGrossProfitMonthRupees`, and `expenseBreakdown` right before `$trend`:

```php
<?php
// app/Reports/DashboardReport.php
namespace App\Reports;

final readonly class DashboardReport
{
    /**
     * @param list<LowStockRow>            $lowStock
     * @param list<ProductPerf>            $productPerformance
     * @param list<ExpenseCategoryTotal>   $expenseBreakdown
     * @param list<TrendRow>               $trend  exactly 12 rows, Jan..Dec
     */
    public function __construct(
        public ReportPeriod $period,
        public string $salesTodayRupees,
        public string $salesMonthRupees,
        public string $estGrossProfitMonthRupees, // sales − est. product cost, before expenses
        public string $expensesMonthRupees,
        public string $netProfitMonthRupees,       // gross − expenses (may be negative)
        public string $netProfitMarginPercent,     // net ÷ sales × 100, one decimal; '0.0' when sales are 0
        public OutstandingSummary $outstanding,
        public string $productionMonthKg,
        public array $lowStock,
        public int $lowStockCount,
        public array $productPerformance,
        public ?string $highestSellingName,
        public ?string $highestProfitName,
        public array $expenseBreakdown,
        public array $trend,
    ) {}
}
```

- [ ] **Step 3: Write the failing test (append)**

```php
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

    $report = inTenant($a->id, fn () => (new DashboardReportService(new App\Services\StockService()))
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

    $report = inTenant($a->id, fn () => (new DashboardReportService(new App\Services\StockService()))
        ->forMonth($a->id, App\Reports\ReportPeriod::fromInput(2026, 7)));

    expect($report->salesMonthRupees)->toBe('0.00');
    expect($report->netProfitMonthRupees)->toBe('-500.00');  // 0 gross − 500 expenses
    expect($report->netProfitMarginPercent)->toBe('0.0');    // guarded
});
```

- [ ] **Step 4: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php --filter="net profit"`
Expected: FAIL — `TrendRow`/`DashboardReport` constructor argument count (or missing fields).

- [ ] **Step 5: Update `forMonth`**

In `app/Services/DashboardReportService.php`, replace the trend-building block and the `DashboardReport` construction. The method currently computes `$salesTrend`, `$prodTrend`, `$grossTrend`. Add the expenses trend, net trend, and the month figures:

```php
    public function forMonth(string $businessId, ReportPeriod $period): DashboardReport
    {
        $salesTrend = $this->salesTrend($businessId, $period->year);
        $prodTrend = $this->productionTrend($businessId, $period->year);
        $grossTrend = $this->grossProfitTrend($businessId, $period->year);
        $expensesTrend = $this->expensesTrend($businessId, $period->year);

        $trend = array_map(
            fn (int $m) => new TrendRow(
                $m,
                $salesTrend[$m - 1],
                $prodTrend[$m - 1],
                $grossTrend[$m - 1],
                $expensesTrend[$m - 1],
                bcsub($grossTrend[$m - 1], $expensesTrend[$m - 1], 2), // net profit (may be negative)
            ),
            range(1, 12),
        );

        $performance = $this->productPerformance($businessId, $period->year);
        $lowStock = $this->lowStock($businessId);

        $highestSelling = collect($performance)
            ->sortByDesc(fn (ProductPerf $p) => $p->qtySold)->first();
        $highestProfit = collect($performance)
            ->sortByDesc(fn (ProductPerf $p) => (float) $p->estProfitRupees)->first();

        // Selected-month P&L figures. Gross and expenses come from the trends we
        // already fetched (index month-1); net = gross − expenses.
        $salesMonth = $this->salesForMonth($businessId, $period->year, $period->month);
        $grossMonth = $grossTrend[$period->month - 1];
        $expensesMonth = $expensesTrend[$period->month - 1];
        $netProfitMonth = bcsub($grossMonth, $expensesMonth, 2);

        $netMargin = bccomp($salesMonth, '0.00', 2) === 0
            ? '0.0'
            : bcadd(bcmul(bcdiv($netProfitMonth, $salesMonth, 4), '100', 2), '0', 1);

        return new DashboardReport(
            period: $period,
            salesTodayRupees: $this->salesToday($businessId),
            salesMonthRupees: $salesMonth,
            estGrossProfitMonthRupees: $grossMonth,
            expensesMonthRupees: $expensesMonth,
            netProfitMonthRupees: $netProfitMonth,
            netProfitMarginPercent: $netMargin,
            outstanding: $this->customerOutstanding($businessId),
            productionMonthKg: $this->productionForMonth($businessId, $period->year, $period->month),
            lowStock: $lowStock,
            lowStockCount: count($lowStock),
            productPerformance: $performance,
            highestSellingName: $highestSelling?->name,
            highestProfitName: $highestProfit?->name,
            expenseBreakdown: $this->expensesByCategory($businessId, $period->year, $period->month),
            trend: $trend,
        );
    }
```

- [ ] **Step 6: Fix the Phase-0 empty-shop test for the new fields**

The existing test `it('assembles a full report, ...')` asserts on `$report`. Add two lines after its existing expectations so the empty case is covered:

```php
    expect($report->expensesMonthRupees)->toBe('0.00');
    expect($report->netProfitMonthRupees)->toBe('0.00');
    expect($report->netProfitMarginPercent)->toBe('0.0');
```

- [ ] **Step 7: Run the whole service test file**

Run: `./vendor/bin/pest tests/Unit/DashboardReportServiceTest.php`
Expected: PASS (all).

- [ ] **Step 8: Commit**

```bash
git add app/Reports/TrendRow.php app/Reports/DashboardReport.php app/Services/DashboardReportService.php tests/Unit/DashboardReportServiceTest.php
git commit -m "feat: compute net profit and net margin in DashboardReport"
```

---

## Task 4: `ExpenseController` + routes + access/CRUD tests

**Files:**
- Create: `app/Http/Controllers/Web/ExpenseController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Web/ExpensesTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php
// tests/Feature/Web/ExpensesTest.php

use App\Models\Business;
use App\Models\Expense;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Str;

/** @return array{0: User, 1: Business} */
function expensesOwner(): array
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
        $this->get('/expenses')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $this->actingAs(User::factory()->create())
            ->get('/expenses')->assertRedirect(route('app'));
    });
});

describe('crud', function () {
    it('records an expense that then appears in the list', function () {
        [$owner, $business] = expensesOwner();

        $this->actingAs($owner)->post('/expenses', [
            'business' => $business->id,
            'category' => 'rent', 'amount' => '5000', 'spent_on' => '2026-07-01', 'note' => null,
        ])->assertRedirect();

        $this->actingAs($owner)
            ->get('/expenses?business=' . $business->id . '&year=2026&month=7')
            ->assertOk()
            ->assertSee('₹5,000.00');

        expect(Expense::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(1);
    });

    it('requires a note when the category is other', function () {
        [$owner, $business] = expensesOwner();

        $this->actingAs($owner)->post('/expenses', [
            'business' => $business->id,
            'category' => 'other', 'amount' => '100', 'spent_on' => '2026-07-01', 'note' => null,
        ])->assertSessionHasErrors('note');
    });

    it('rejects an unknown category and a non-positive amount', function () {
        [$owner, $business] = expensesOwner();

        $this->actingAs($owner)->post('/expenses', [
            'business' => $business->id, 'category' => 'groceries', 'amount' => '100', 'spent_on' => '2026-07-01',
        ])->assertSessionHasErrors('category');

        $this->actingAs($owner)->post('/expenses', [
            'business' => $business->id, 'category' => 'rent', 'amount' => '0', 'spent_on' => '2026-07-01',
        ])->assertSessionHasErrors('amount');
    });

    it('edits and archives an owned expense', function () {
        [$owner, $business] = expensesOwner();
        $e = Expense::on('pgsql_migrate')->create([
            'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
            'category' => 'rent', 'amount' => '5000.00', 'spent_on' => '2026-07-01',
            'created_by' => $owner->id,
        ]);

        // Edit.
        $this->actingAs($owner)->put('/expenses/' . $e->id, [
            'business' => $business->id,
            'category' => 'rent', 'amount' => '5500', 'spent_on' => '2026-07-01', 'note' => 'revised',
        ])->assertRedirect();
        expect(Expense::on('pgsql_migrate')->find($e->id)->amount)->toBe('5500.00');

        // Archive (soft delete).
        $this->actingAs($owner)->delete('/expenses/' . $e->id, ['business' => $business->id])
            ->assertRedirect();
        expect(Expense::on('pgsql_migrate')->find($e->id)->archived_at)->not->toBeNull();
    });

    it('refuses to touch another tenant\'s expense', function () {
        [$owner, $business] = expensesOwner();
        [, $other] = expensesOwner();
        $foreign = Expense::on('pgsql_migrate')->create([
            'business_id' => $other->id, 'uuid' => (string) Str::uuid(),
            'category' => 'rent', 'amount' => '9999.00', 'spent_on' => '2026-07-01',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)->delete('/expenses/' . $foreign->id, ['business' => $business->id])
            ->assertRedirect();
        // Untouched.
        expect(Expense::on('pgsql_migrate')->find($foreign->id)->archived_at)->toBeNull();
    });

    it('is idempotent on a replayed uuid', function () {
        [$owner, $business] = expensesOwner();
        $uuid = (string) Str::uuid();

        $payload = [
            'business' => $business->id, 'uuid' => $uuid,
            'category' => 'rent', 'amount' => '5000', 'spent_on' => '2026-07-01',
        ];
        $this->actingAs($owner)->post('/expenses', $payload);
        $this->actingAs($owner)->post('/expenses', $payload);   // replay

        expect(Expense::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(1);
    });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Web/ExpensesTest.php`
Expected: FAIL — route `/expenses` not defined.

- [ ] **Step 3: Create the controller**

```php
<?php
// app/Http/Controllers/Web/ExpenseController.php

namespace App\Http\Controllers\Web;

use App\Expenses\ExpenseCategory;
use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Reports\ReportPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Operating-expenses entry (Phase 1): Blade, online-only, owner-only. Same
 * owner-tool pattern as BillingController/ReportController — the caller's OWNED
 * business is resolved from their membership (never the request), and work runs
 * tenant-pinned (RLS + app scope + owner). Not behind the write plan-gate: a
 * lapsed owner still records their own bookkeeping, like the billing page.
 */
class ExpenseController extends Controller
{
    use ResolvesOwnedTenant;

    /** The selected month's expenses + total + add form. */
    public function index(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $period = ReportPeriod::fromInput(
            $request->integer('year') ?: null,
            $request->integer('month') ?: null,
        );

        [$expenses, $total] = $this->runInTenant($businessId, function () use ($period) {
            $rows = Expense::query()
                ->whereNull('archived_at')
                ->whereRaw('extract(year from spent_on) = ?', [$period->year])
                ->whereRaw('extract(month from spent_on) = ?', [$period->month])
                ->orderByDesc('spent_on')
                ->get();

            $total = $rows->reduce(fn (string $c, Expense $e) => bcadd($c, (string) $e->amount, 2), '0.00');

            return [$rows, $total];
        });

        return view('expenses.index', [
            'businessId' => $businessId,
            'period' => $period,
            'expenses' => $expenses,
            'total' => $total,
            'categories' => ExpenseCategory::keys(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $this->validated($request);
        $uuid = $request->input('uuid') ?: (string) Str::uuid();

        $this->runInTenant($businessId, function () use ($data, $uuid) {
            if (Expense::where('uuid', $uuid)->exists()) {
                return; // idempotent replay — do not append a second row
            }
            $expense = new Expense([
                'uuid' => $uuid,
                'category' => $data['category'],
                'amount' => bcadd((string) $data['amount'], '0', 2),
                'spent_on' => $data['spent_on'],
                'note' => $data['note'] ?? null,
            ]);
            // business_id via BelongsToTenant, created_by from the tenant context.
            $expense->created_by = app('tenant.user_id');
            $expense->save();
        });

        return redirect()->route('expenses', $this->periodQuery($request, $businessId));
    }

    public function update(Request $request, string $expense): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $this->validated($request);

        $this->runInTenant($businessId, function () use ($businessId, $expense, $data) {
            // Explicit owner scope — never trust the id alone.
            $row = Expense::where('business_id', $businessId)->whereNull('archived_at')->find($expense);
            $row?->update([
                'category' => $data['category'],
                'amount' => bcadd((string) $data['amount'], '0', 2),
                'spent_on' => $data['spent_on'],
                'note' => $data['note'] ?? null,
            ]);
        });

        return redirect()->route('expenses', $this->periodQuery($request, $businessId));
    }

    public function destroy(Request $request, string $expense): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $this->runInTenant($businessId, function () use ($businessId, $expense) {
            $row = Expense::where('business_id', $businessId)->whereNull('archived_at')->find($expense);
            $row?->update(['archived_at' => now()]);
        });

        return redirect()->route('expenses', $this->periodQuery($request, $businessId));
    }

    /** @return array{category: string, amount: string, spent_on: string, note: ?string} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'category' => ['required', Rule::in(ExpenseCategory::keys())],
            'amount' => ['required', 'numeric', 'gt:0'],
            'spent_on' => ['required', 'date'],
            'note' => [
                Rule::requiredIf(fn () => ExpenseCategory::requiresNote((string) $request->input('category'))),
                'nullable', 'string', 'max:255',
            ],
        ]);
    }

    /** Preserve business + period on the redirect back to the list. */
    private function periodQuery(Request $request, string $businessId): array
    {
        return array_filter([
            'business' => $businessId,
            'year' => $request->integer('year') ?: null,
            'month' => $request->integer('month') ?: null,
        ]);
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add the import near the other `Web\` imports:

```php
use App\Http\Controllers\Web\ExpenseController;
```

Inside the existing `Route::middleware('auth')->group(...)` block, right after the `reports/dashboard` route:

```php
    /*
     | Operating expenses (Phase 1) — Blade, online-only, owner-only. Same
     | owner-tool pattern as billing/reports; not behind the plan gate.
     | {expense} is resolved owner-scoped inside the controller, never via
     | implicit binding (no tenant is pinned during route resolution).
     */
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
```

- [ ] **Step 5: Run the feature test**

Run: `./vendor/bin/pest tests/Feature/Web/ExpensesTest.php`
Expected: access + crud tests PASS, **except** the one asserting `->assertSee('₹5,000.00')` on `GET /expenses` — that FAILS because the `expenses.index` view does not exist yet. That is expected; Task 5 adds the view. All non-render assertions (store/update/destroy/validation/idempotency/isolation) pass now.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Web/ExpenseController.php routes/web.php tests/Feature/Web/ExpensesTest.php
git commit -m "feat: add expenses CRUD controller and routes (owner-only)"
```

---

## Task 5: Expenses Blade views + language files

**Files:**
- Create: `resources/views/expenses/index.blade.php`, `resources/views/expenses/partials/form.blade.php`, `resources/views/expenses/partials/list.blade.php`
- Create: `lang/en/expenses.php`, `lang/hi/expenses.php`

- [ ] **Step 1: Create the English language file**

```php
<?php
// lang/en/expenses.php

return [
    'title' => 'Expenses',
    'heading' => 'Operating expenses',
    'back_to_dashboard' => 'Back to dashboard',

    'add' => 'Add expense',
    'category' => 'Category',
    'amount' => 'Amount',
    'date' => 'Date',
    'note' => 'Note',
    'note_other_hint' => 'Required for “Other”',
    'save' => 'Save',
    'update' => 'Update',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'month_total' => 'Total this month',
    'no_expenses' => 'No expenses recorded for this month yet.',
    'operating_only' => 'Operating expenses only — do not enter stock or raw-material purchases here.',

    'categories' => [
        'rent' => 'Rent',
        'salaries' => 'Salaries / Wages',
        'electricity' => 'Electricity',
        'transport' => 'Transport / Fuel',
        'maintenance' => 'Maintenance',
        'other' => 'Other',
    ],
];
```

- [ ] **Step 2: Create the Hindi language file**

```php
<?php
// lang/hi/expenses.php

return [
    'title' => 'खर्च',
    'heading' => 'परिचालन खर्च',
    'back_to_dashboard' => 'डैशबोर्ड पर वापस',

    'add' => 'खर्च जोड़ें',
    'category' => 'श्रेणी',
    'amount' => 'राशि',
    'date' => 'तारीख',
    'note' => 'टिप्पणी',
    'note_other_hint' => '“अन्य” के लिए आवश्यक',
    'save' => 'सहेजें',
    'update' => 'अपडेट करें',
    'edit' => 'संपादित करें',
    'delete' => 'हटाएँ',
    'month_total' => 'इस महीने का कुल',
    'no_expenses' => 'इस महीने अभी तक कोई खर्च दर्ज नहीं हुआ।',
    'operating_only' => 'केवल परिचालन खर्च — स्टॉक या कच्चे माल की खरीद यहाँ दर्ज न करें।',

    'categories' => [
        'rent' => 'किराया',
        'salaries' => 'वेतन / मज़दूरी',
        'electricity' => 'बिजली',
        'transport' => 'परिवहन / ईंधन',
        'maintenance' => 'रखरखाव',
        'other' => 'अन्य',
    ],
];
```

- [ ] **Step 3: Create the add/edit form partial**

```blade
{{-- resources/views/expenses/partials/form.blade.php --}}
{{-- $editing (Expense|null) — when set, the form PUTs an update. --}}
@php $editing = $editing ?? null; @endphp
<form method="POST"
      action="{{ $editing ? route('expenses.update', array_filter(['expense' => $editing->id, 'business' => $businessId, 'year' => $period->year, 'month' => $period->month])) : route('expenses.store') }}"
      class="card grid gap-3 md:grid-cols-5 md:items-end">
    @csrf
    @if ($editing) @method('PUT') @endif
    <input type="hidden" name="business" value="{{ $businessId }}">
    <input type="hidden" name="year" value="{{ $period->year }}">
    <input type="hidden" name="month" value="{{ $period->month }}">

    <label class="text-sm">
        <span class="block text-ink-muted">{{ __('expenses.category') }}</span>
        <select name="category" class="field-input">
            @foreach ($categories as $key)
                <option value="{{ $key }}" @selected($editing && $editing->category === $key)>
                    {{ __('expenses.categories.' . $key) }}
                </option>
            @endforeach
        </select>
    </label>

    <label class="text-sm">
        <span class="block text-ink-muted">{{ __('expenses.amount') }}</span>
        <input type="number" step="0.01" min="0.01" name="amount" class="field-input"
               value="{{ old('amount', $editing?->amount) }}" required>
    </label>

    <label class="text-sm">
        <span class="block text-ink-muted">{{ __('expenses.date') }}</span>
        <input type="date" name="spent_on" class="field-input"
               value="{{ old('spent_on', $editing?->spent_on?->format('Y-m-d')) }}" required>
    </label>

    <label class="text-sm md:col-span-1">
        <span class="block text-ink-muted">{{ __('expenses.note') }}</span>
        <input type="text" name="note" maxlength="255" class="field-input"
               value="{{ old('note', $editing?->note) }}" placeholder="{{ __('expenses.note_other_hint') }}">
    </label>

    <button type="submit" class="btn-primary">{{ $editing ? __('expenses.update') : __('expenses.save') }}</button>

    @error('category') <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
    @error('amount')   <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
    @error('spent_on') <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
    @error('note')     <p class="md:col-span-5 text-sm text-danger">{{ $message }}</p> @enderror
</form>
```

- [ ] **Step 4: Create the list partial**

```blade
{{-- resources/views/expenses/partials/list.blade.php --}}
@php use App\Support\Inr; @endphp
<div class="card mt-4 overflow-x-auto">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="font-semibold">{{ __('expenses.heading') }}</h2>
        <p class="tabular font-bold">{{ __('expenses.month_total') }}: {{ Inr::format($total) }}</p>
    </div>

    @if ($expenses->isEmpty())
        <p class="text-sm text-ink-muted">{{ __('expenses.no_expenses') }}</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('expenses.date') }}</th>
                    <th>{{ __('expenses.category') }}</th>
                    <th>{{ __('expenses.note') }}</th>
                    <th class="text-right">{{ __('expenses.amount') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($expenses as $e)
                    <tr>
                        <td class="tabular">{{ $e->spent_on->format('d M Y') }}</td>
                        <td>{{ __('expenses.categories.' . $e->category) }}</td>
                        <td>{{ $e->note ?? '—' }}</td>
                        <td class="tabular text-right">{{ Inr::format($e->amount) }}</td>
                        <td class="text-right">
                            <form method="POST"
                                  action="{{ route('expenses.destroy', array_filter(['expense' => $e->id, 'business' => $businessId, 'year' => $period->year, 'month' => $period->month])) }}"
                                  onsubmit="return confirm('{{ __('expenses.delete') }}?')">
                                @csrf @method('DELETE')
                                <input type="hidden" name="business" value="{{ $businessId }}">
                                <button type="submit" class="text-danger">{{ __('expenses.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
```

> **Scope note:** inline edit (an "Edit" row action that repopulates the form) is
> supported by the controller's `update` route and the form partial's `$editing`
> branch, but wiring a per-row edit toggle is optional polish. The `delete`
> action is wired above; the `update` route is exercised by the feature test in
> Task 4. Leave a plain add-only form for Phase 1 if you prefer — the `$editing`
> branch stays dormant.

- [ ] **Step 5: Create the index view**

```blade
{{-- resources/views/expenses/index.blade.php --}}
@extends('layouts.app')

@section('title', __('expenses.title') . ' — ' . config('app.name'))

@section('content')
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('expenses.heading') }}</h1>
        <a href="{{ route('reports.dashboard', ['business' => $businessId]) }}"
           class="text-sm text-brand">{{ __('expenses.back_to_dashboard') }}</a>
    </header>

    <p class="mb-3 text-xs text-ink-muted">{{ __('expenses.operating_only') }}</p>

    {{-- Month/year picker (GET), same shape as the dashboard. --}}
    <form method="GET" action="{{ route('expenses') }}" class="mb-4 flex flex-wrap items-end gap-2">
        <input type="hidden" name="business" value="{{ $businessId }}">
        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('reports.month') }}</span>
            <select name="month" class="field-input">
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" @selected($m === $period->month)>
                        {{ \Illuminate\Support\Carbon::create()->month($m)->translatedFormat('F') }}
                    </option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="block text-ink-muted">{{ __('reports.period') }}</span>
            <select name="year" class="field-input">
                @foreach (range((int) date('Y'), 2020) as $y)
                    <option value="{{ $y }}" @selected($y === $period->year)>{{ $y }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn-primary">{{ __('reports.view') }}</button>
    </form>

    @include('expenses.partials.form')
    @include('expenses.partials.list')
</div>
@endsection
```

- [ ] **Step 6: Run the expenses feature test (now fully green)**

Run: `php artisan view:clear && ./vendor/bin/pest tests/Feature/Web/ExpensesTest.php`
Expected: PASS (all).

- [ ] **Step 7: Commit**

```bash
git add resources/views/expenses/ lang/en/expenses.php lang/hi/expenses.php
git commit -m "feat: add expenses entry Blade views and translations"
```

---

## Task 6: Dashboard P&L block, category breakdown, net-profit chart + trend columns

**Files:**
- Create: `resources/views/reports/partials/pnl.blade.php`
- Modify: `resources/views/reports/dashboard.blade.php`, `resources/views/reports/partials/{trend,charts}.blade.php`
- Modify: `lang/en/reports.php`, `lang/hi/reports.php`
- Test: `tests/Feature/Web/ReportsDashboardTest.php`

- [ ] **Step 1: Add the failing render assertion**

In `tests/Feature/Web/ReportsDashboardTest.php`, extend the existing render test that seeds a customer. Seed an expense and assert the P&L labels + net profit show. Replace the `it('shows the dashboard heading ...')` body's setup + assertions with:

```php
    it('shows the dashboard heading and the total-due figure for the owner', function () {
        [$owner, $business] = reportsOwner();
        Customer::on('pgsql_migrate')->create([
            'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
            'name' => 'Ramesh', 'village' => 'Rampur', 'opening_balance' => '1500.00',
        ]);
        App\Models\Expense::on('pgsql_migrate')->create([
            'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
            'category' => 'rent', 'amount' => '1200.00', 'spent_on' => now()->format('Y-m-d'),
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->get('/reports/dashboard')
            ->assertOk()
            ->assertSee(__('reports.heading'))
            ->assertSee(__('reports.customer_outstanding'))
            ->assertSee('₹1,500.00')
            ->assertSee('Ramesh')
            ->assertSee('Rampur')
            ->assertSee(__('reports.est_gross_profit'))  // gross-profit row in the P&L block
            ->assertSee(__('reports.gross_profit_caveat'))
            ->assertSee(__('reports.net_profit'))        // P&L block renders
            ->assertSee(__('reports.expenses'));         // expenses line in P&L
    });
```

> **Note:** this replaces the Phase-0 assertion on `est_gross_profit_month`. The
> standalone gross-profit card (`gross-profit.blade.php`, which used
> `est_gross_profit_month`) is superseded by the P&L block, which shows the same
> figure labelled `est_gross_profit`. The `est_gross_profit_month` key becomes
> unused (harmless) once the card is deleted in Step 5.

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Web/ReportsDashboardTest.php --filter="total-due"`
Expected: FAIL — `reports.net_profit` translation missing / P&L not rendered.

- [ ] **Step 3: Add the P&L / net-profit language keys**

In `lang/en/reports.php`, after the existing gross-profit block, add:

```php
    // P&L (Phase 1)
    'pnl' => 'Profit & loss (this month)',
    'expenses' => 'Operating expenses',
    'net_profit' => 'Net profit',
    'net_margin' => 'Net margin',
    'expenses_by_category' => 'Expenses by category',
    'manage_expenses' => 'Manage expenses',
    'monthly_net_profit_chart' => 'Monthly net profit',
```

In `lang/hi/reports.php`, the same keys:

```php
    'pnl' => 'लाभ और हानि (इस महीने)',
    'expenses' => 'परिचालन खर्च',
    'net_profit' => 'शुद्ध लाभ',
    'net_margin' => 'शुद्ध मार्जिन',
    'expenses_by_category' => 'श्रेणी अनुसार खर्च',
    'manage_expenses' => 'खर्च प्रबंधित करें',
    'monthly_net_profit_chart' => 'मासिक शुद्ध लाभ',
```

- [ ] **Step 4: Create the P&L partial**

```blade
{{-- resources/views/reports/partials/pnl.blade.php --}}
@php
    use App\Support\Inr;
    $net = $report->netProfitMonthRupees;
    $isLoss = bccomp($net, '0.00', 2) < 0;
@endphp
<div class="card mt-4">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="font-semibold">{{ __('reports.pnl') }}</h2>
        <a href="{{ route('expenses', ['business' => $businessId, 'year' => $report->period->year, 'month' => $report->period->month]) }}"
           class="text-sm text-brand">{{ __('reports.manage_expenses') }}</a>
    </div>

    <dl class="space-y-1 text-sm">
        <div class="flex justify-between">
            <dt>{{ __('reports.sales_month') }}</dt>
            <dd class="tabular">{{ Inr::format($report->salesMonthRupees) }}</dd>
        </div>
        <div class="flex justify-between text-ink-muted">
            <dt>− {{ __('reports.est_cost') }}</dt>
            <dd class="tabular">{{ Inr::format(bcsub($report->salesMonthRupees, $report->estGrossProfitMonthRupees, 2)) }}</dd>
        </div>
        <div class="flex justify-between border-t pt-1">
            <dt>= {{ __('reports.est_gross_profit') }}</dt>
            <dd class="tabular">{{ Inr::format($report->estGrossProfitMonthRupees) }}</dd>
        </div>
        <div class="flex justify-between text-ink-muted">
            <dt>− {{ __('reports.expenses') }}</dt>
            <dd class="tabular">{{ Inr::format($report->expensesMonthRupees) }}</dd>
        </div>
        <div class="flex justify-between border-t pt-1 text-base font-bold {{ $isLoss ? 'text-danger' : 'text-success' }}">
            <dt>= {{ __('reports.net_profit') }}</dt>
            <dd class="tabular">{{ Inr::format($net) }} <span class="text-xs font-normal">({{ $report->netProfitMarginPercent }}% {{ __('reports.net_margin') }})</span></dd>
        </div>
    </dl>

    <p class="mt-2 text-xs text-ink-muted">{{ __('reports.gross_profit_caveat') }}</p>

    @if ($report->expenseBreakdown !== [])
        <h3 class="mt-3 mb-1 text-sm font-semibold">{{ __('reports.expenses_by_category') }}</h3>
        <table class="w-full text-sm">
            <tbody>
                @foreach ($report->expenseBreakdown as $row)
                    <tr>
                        <td>{{ __('expenses.categories.' . $row->category) }}</td>
                        <td class="tabular text-right">{{ Inr::format($row->amountRupees) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
```

- [ ] **Step 5: Include the P&L partial in the dashboard**

In `resources/views/reports/dashboard.blade.php`, replace the existing gross-profit include with the P&L block (the P&L now contains the gross-profit figure and the caveat, so the standalone gross-profit card is superseded):

Change:
```blade
    @include('reports.partials.tiles')
    @include('reports.partials.gross-profit')
    @include('reports.partials.insights')
```
to:
```blade
    @include('reports.partials.tiles')
    @include('reports.partials.pnl')
    @include('reports.partials.insights')
```

Then delete the now-superseded standalone card:
```bash
git rm resources/views/reports/partials/gross-profit.blade.php
```
Its gross-profit figure and caveat both live in the P&L block now (labelled `est_gross_profit` + `gross_profit_caveat`), which is exactly what the Step 1 assertions check. The `est_gross_profit_month` lang key is left in place but unused — harmless; leave it rather than churn the tiles.

- [ ] **Step 6: Add the net-profit chart and trend columns**

In `resources/views/reports/partials/charts.blade.php`, add a net-profit series and chart. After the `$grossValues` line add:
```blade
    $netValues = collect($report->trend)->map(fn ($t) => $t->netProfitRupees)->all();
```
and after the gross-profit `<x-svg-bar-chart>` add:
```blade
    <x-svg-bar-chart :values="$netValues" :labels="$months" :title="__('reports.monthly_net_profit_chart')" />
```

In `resources/views/reports/partials/trend.blade.php`, add Expenses + Net-profit columns. In the `<thead>` after the `est_gross_profit` header add:
```blade
                <th class="text-right">{{ __('reports.expenses') }}</th>
                <th class="text-right">{{ __('reports.net_profit') }}</th>
```
and in the `<tbody>` row, after the gross-profit cell add:
```blade
                    <td class="tabular text-right">{{ Inr::format($row->expensesRupees) }}</td>
                    <td class="tabular text-right {{ bccomp($row->netProfitRupees, '0.00', 2) < 0 ? 'text-danger' : '' }}">{{ Inr::format($row->netProfitRupees) }}</td>
```

- [ ] **Step 7: Run the dashboard render tests**

Run: `php artisan view:clear && ./vendor/bin/pest tests/Feature/Web/ReportsDashboardTest.php`
Expected: PASS (all).

- [ ] **Step 8: Commit**

```bash
# gross-profit.blade.php was already `git rm`'d in Step 5; -A stages that deletion too.
git add -A resources/views/reports/ lang/en/reports.php lang/hi/reports.php tests/Feature/Web/ReportsDashboardTest.php
git commit -m "feat: add dashboard P&L block, net-profit chart and trend columns"
```

---

## Task 7: Owner nav link — dashboard → expenses

The P&L partial already links to `/expenses` via "Manage expenses" (Task 6). Add a matching top-level link in the dashboard header so it is discoverable even before scrolling to the P&L.

**Files:**
- Modify: `resources/views/reports/dashboard.blade.php`

- [ ] **Step 1: Add the header link**

In `resources/views/reports/dashboard.blade.php`, change the header's single "Back to app" anchor into two links:

```blade
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('reports.heading') }}</h1>
        <nav class="flex gap-3 text-sm">
            <a href="{{ route('expenses', ['business' => $businessId]) }}" class="text-brand">{{ __('reports.manage_expenses') }}</a>
            <a href="{{ route('app') }}" class="text-brand">{{ __('reports.back_to_app') }}</a>
        </nav>
    </header>
```

- [ ] **Step 2: Verify the dashboard still renders**

Run: `php artisan view:clear && ./vendor/bin/pest tests/Feature/Web/ReportsDashboardTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add resources/views/reports/dashboard.blade.php
git commit -m "feat: link the dashboard header to expense management"
```

---

## Task 8: Full-suite green + wrap-up

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test`
Expected: all green, including the new `ExpenseCategoryTest`, the extended `DashboardReportServiceTest`, `ExpensesTest`, and `ReportsDashboardTest`.

- [ ] **Step 2: Manually verify (optional but recommended)**

```bash
php artisan db:seed --class=DemoDataSeeder   # already-seeded is fine
php artisan serve
```
Log in as `owner@demo-namkeen-bhandar.test` / `password123`. Visit `/expenses`, add a couple of expenses (e.g. rent 5000, salaries 3000), then open `/reports/dashboard` and confirm the P&L shows Net Profit = Est. Gross Profit − Expenses, the by-category table, the net-profit chart, and the new trend columns. Try a month with expenses > gross to see the loss render in danger colour.

- [ ] **Step 3: Update the UI backlog**

In `docs/ui-backlog.md` (repo root, above `backend/`), add an `F-02` row under Features noting the Phase 1 expenses + net-profit feature shipped, referencing this plan and the spec.

- [ ] **Step 4: Final commit**

```bash
git add docs/ui-backlog.md
git commit -m "docs: log Phase 1 expenses & net-profit in ui-backlog"
```

- [ ] **Step 5: Finish the branch**

Use the `finishing-a-development-branch` skill (merge to master or open a PR, per preference). Note: CI was removed in Phase 0 wrap-up, so verification is the local `php artisan test` run from Step 1.

---

## Self-review notes (traceability to the spec)

- **Expenses table + model + category SoT** (spec §Data model, §Categories): Task 1 — uuid PK, RLS + `BelongsToTenant`, soft-delete, no sync columns; `ExpenseCategory` drives validation/breakdown/labels.
- **Owner-only CRUD, tenant isolation** (spec §Expense entry, §Error handling): Task 4 — `ResolvesOwnedTenant`, tenant-pinned, owner re-check on update/delete, idempotent create, `other` requires note, unknown category / non-positive amount rejected.
- **Aggregation + Net Profit** (spec §Dashboard P&L integration, §Computable): Tasks 2–3 — expenses total/by-category/trend; Net = Gross − Expenses (loss case tested); margin zero-sales guarded; trend rows carry expenses + net.
- **P&L UI + charts** (spec §Dashboard P&L integration): Task 6 — P&L block with the estimated caption, by-category table, net-profit chart, Expenses/Net-profit trend columns; loss shown in danger colour.
- **Negative net-profit chart behaviour** (spec §Error handling): documented — loss months render flat in the SVG chart; the trend table carries the exact signed figure.
- **Operating-expenses-only** (spec Decision 3): enforced by the category list; the entry screen shows the "do not enter stock purchases" hint.
- **Estimated caveat** (spec Decision 5): the P&L keeps `gross_profit_caveat`.
- **Navigation** (spec §Navigation): Task 6 P&L "Manage expenses" link + Task 7 dashboard header link.
- **Testing** (spec §Testing): unit (category, service math incl. loss + zero-sales) + feature (CRUD, isolation, idempotency, dashboard P&L render).
