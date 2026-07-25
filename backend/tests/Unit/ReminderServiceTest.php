<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\ReminderService;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Tenant-pinned run, as the controller does in prod. */
function inRemTenant(string $businessId, callable $fn): mixed
{
    return DB::transaction(function () use ($businessId, $fn) {
        TenantContext::switchTo($businessId);
        app()->bind('tenant.id', fn () => $businessId);

        return $fn();
    });
}

function remCustomer(Business $b, string $name, ?string $phone = '9876543210', string $opening = '0.00'): Customer
{
    return Customer::on('pgsql_migrate')->create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'village' => 'Rampur', 'phone' => $phone,
        'opening_balance' => $opening,
    ]);
}

function remSale(Customer $c, User $u, string $total, string $date): void
{
    $s = new Sale([
        'business_id' => $c->business_id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'sale_date' => $date,
    ]);
    $s->setConnection('pgsql_migrate');
    // total and created_by are deliberately not fillable (SaleWriter stamps them).
    $s->total = $total;
    $s->created_by = $u->id;
    $s->save();
}

function remPayment(Customer $c, User $u, string $amount, string $date): void
{
    $p = new Payment([
        'business_id' => $c->business_id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'payment_date' => $date, 'amount' => $amount, 'mode' => 'cash',
    ]);
    $p->setConnection('pgsql_migrate');
    $p->created_by = $u->id;
    $p->save();
}

/** @return list<App\Reminders\OverdueCustomer> */
function overdueFor(Business $b): array
{
    return inRemTenant($b->id, fn () => app(ReminderService::class)->overdue($b->id));
}

beforeEach(function () {
    // Fixed "today" so days-overdue arithmetic is deterministic.
    Carbon::setTestNow('2026-07-25');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('includes a customer exactly at both thresholds, and excludes one just under', function () {
    $b = Business::factory()->create();  // defaults: 500.00 / 30 days
    $u = User::factory()->create();

    // Exactly 500.00 owed, last paid exactly 30 days ago → included.
    $atThreshold = remCustomer($b, 'Exactly At');
    remSale($atThreshold, $u, '1500.00', '2026-05-01');
    remPayment($atThreshold, $u, '1000.00', '2026-06-25');   // 30 days before 07-25

    // 499.99 owed → under the money threshold.
    $tooSmall = remCustomer($b, 'Too Small');
    remSale($tooSmall, $u, '1499.99', '2026-05-01');
    remPayment($tooSmall, $u, '1000.00', '2026-06-25');

    // Paid 29 days ago → under the days threshold.
    $tooRecent = remCustomer($b, 'Too Recent');
    remSale($tooRecent, $u, '1500.00', '2026-05-01');
    remPayment($tooRecent, $u, '1000.00', '2026-06-26');

    $names = array_map(fn ($r) => $r->name, overdueFor($b));

    expect($names)->toContain('Exactly At');
    expect($names)->not->toContain('Too Small');
    expect($names)->not->toContain('Too Recent');
});

it('lists an opted-out customer but refuses to send to them', function () {
    $b = Business::factory()->create();
    $u = User::factory()->create();

    $c = remCustomer($b, 'Opted Out');
    remSale($c, $u, '2000.00', '2026-05-01');
    $c->reminder_opt_out_at = '2026-07-01 10:00:00';
    $c->save();

    $rows = overdueFor($b);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->name)->toBe('Opted Out');
    expect($rows[0]->sendable)->toBeFalse();
    expect($rows[0]->blockedReason)->toBe('opted_out');
});

it('lists a customer with no phone, and one whose phone is unusable, as not sendable', function () {
    $b = Business::factory()->create();
    $u = User::factory()->create();

    $noPhone = remCustomer($b, 'No Phone', phone: null);
    remSale($noPhone, $u, '2000.00', '2026-05-01');

    $badPhone = remCustomer($b, 'Bad Phone', phone: '12345');
    remSale($badPhone, $u, '2000.00', '2026-05-01');

    $rows = collect(overdueFor($b))->keyBy('name');

    expect($rows['No Phone']->sendable)->toBeFalse();
    expect($rows['No Phone']->blockedReason)->toBe('no_phone');
    expect($rows['Bad Phone']->sendable)->toBeFalse();
    expect($rows['Bad Phone']->blockedReason)->toBe('bad_phone');
});

it('excludes a customer who owes nothing or is in credit', function () {
    $b = Business::factory()->create();
    $u = User::factory()->create();

    $settled = remCustomer($b, 'Settled');
    remSale($settled, $u, '2000.00', '2026-05-01');
    remPayment($settled, $u, '2000.00', '2026-05-02');

    $inCredit = remCustomer($b, 'In Credit');
    remSale($inCredit, $u, '2000.00', '2026-05-01');
    remPayment($inCredit, $u, '2500.00', '2026-05-02');   // advance

    expect(overdueFor($b))->toBeEmpty();
});

it('counts days from the first sale when the customer has never paid', function () {
    $b = Business::factory()->create();
    $u = User::factory()->create();

    $c = remCustomer($b, 'Never Paid');
    remSale($c, $u, '3000.00', '2026-05-26');   // 60 days before 2026-07-25

    $rows = overdueFor($b);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->lastPaymentOn)->toBeNull();
    expect($rows[0]->daysOverdue)->toBe(60);
});

it('sorts by outstanding descending, biggest debt first', function () {
    $b = Business::factory()->create();
    $u = User::factory()->create();

    foreach (['Small' => '800.00', 'Huge' => '9000.00', 'Medium' => '3000.00'] as $name => $total) {
        remSale(remCustomer($b, $name), $u, $total, '2026-05-01');
    }

    expect(array_map(fn ($r) => $r->name, overdueFor($b)))->toBe(['Huge', 'Medium', 'Small']);
});

it('respects per-shop thresholds rather than hardcoding the defaults', function () {
    $b = Business::factory()->create();
    $u = User::factory()->create();
    DB::connection('pgsql_migrate')->table('businesses')->where('id', $b->id)
        ->update(['reminder_min_outstanding' => '5000.00', 'reminder_min_days' => 90]);

    $under = remCustomer($b, 'Under New Bar');
    remSale($under, $u, '3000.00', '2026-05-01');     // was overdue at defaults

    $over = remCustomer($b, 'Over New Bar');
    remSale($over, $u, '6000.00', '2026-01-01');      // big and old enough

    expect(array_map(fn ($r) => $r->name, overdueFor($b)))->toBe(['Over New Bar']);
});

it('never leaks another tenant\'s overdue customers', function () {
    $a = Business::factory()->create();
    $other = Business::factory()->create();
    $u = User::factory()->create();

    remSale(remCustomer($a, 'Mine'), $u, '2000.00', '2026-05-01');
    remSale(remCustomer($other, 'Theirs'), $u, '9000.00', '2026-05-01');

    $names = array_map(fn ($r) => $r->name, overdueFor($a));

    expect($names)->toBe(['Mine']);
});

it('ignores archived customers', function () {
    $b = Business::factory()->create();
    $u = User::factory()->create();

    $gone = remCustomer($b, 'Archived');
    remSale($gone, $u, '2000.00', '2026-05-01');
    $gone->archived_at = '2026-07-01 00:00:00';
    $gone->save();

    expect(overdueFor($b))->toBeEmpty();
});
