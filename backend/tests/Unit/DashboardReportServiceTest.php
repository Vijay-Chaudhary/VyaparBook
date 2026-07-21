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

    $summary = inTenant($a->id, fn () => (new DashboardReportService(new KhataService(), new App\Services\StockService()))
        ->customerOutstanding($a->id));

    expect($summary->totalRupees)->toBe($expectedTotal)   // '1628.50'
        ->and($summary->totalRupees)->toBe('1628.50');

    // Sorted highest-due first, and business b's customer is absent.
    expect($summary->customers)->toHaveCount(2);
    expect($summary->customers[0]->name)->toBe('Ramesh');
    expect(collect($summary->customers)->pluck('name'))->not->toContain('Other Shop Customer');
});
