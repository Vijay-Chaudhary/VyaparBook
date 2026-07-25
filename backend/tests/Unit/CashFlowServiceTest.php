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
