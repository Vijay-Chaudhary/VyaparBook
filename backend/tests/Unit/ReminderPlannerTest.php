<?php

use App\Models\Business;
use App\Models\Customer;
use App\Models\ReminderBatch;
use App\Models\ReminderLog;
use App\Models\Sale;
use App\Models\User;
use App\Services\ReminderPlanner;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function inPlanTenant(string $businessId, callable $fn): mixed
{
    return DB::transaction(function () use ($businessId, $fn) {
        TenantContext::switchTo($businessId);
        app()->bind('tenant.id', fn () => $businessId);

        return $fn();
    });
}

/** A tenant with automation switched on. */
function autoBusiness(array $settings = []): array
{
    $business = Business::factory()->create();
    $owner = User::factory()->create();
    DB::table('businesses')->where('id', $business->id)
        ->update(['reminder_auto_enabled' => true] + $settings);

    return [$business->fresh(), $owner];
}

function planCustomer(Business $b, User $u, string $name, string $total = '2000.00', ?string $phone = '9876543210'): Customer
{
    $c = Customer::create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'village' => 'Rampur', 'phone' => $phone, 'opening_balance' => '0.00',
    ]);

    $s = new Sale([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'sale_date' => now()->subDays(60)->format('Y-m-d'),
    ]);
    $s->total = $total;
    $s->created_by = $u->id;
    $s->save();

    return $c;
}

/** A previous reminder for this customer — automated when $batchId is given. */
function priorReminder(Customer $c, User $u, string $daysAgo, ?string $batchId = null): void
{
    $log = new ReminderLog([
        'business_id' => $c->business_id, 'customer_id' => $c->id,
        'channel' => 'cloud_api', 'amount_at_send' => '2000.00',
        'locale' => 'en', 'phone_e164' => '919876543210', 'batch_id' => $batchId,
    ]);
    $log->created_by = $u->id;
    $log->status = 'sent';
    $log->created_at = now()->subDays((int) $daysAgo);
    $log->save();
}

function planFor(Business $b): ?ReminderBatch
{
    return inPlanTenant($b->id, fn () => app(ReminderPlanner::class)->planFor($b->id, Carbon::today()));
}

function plannedNames(Business $b): array
{
    return ReminderLog::where('business_id', $b->id)->where('status', 'planned')
        ->get()
        ->map(fn ($l) => Customer::find($l->customer_id)->name)
        ->sort()->values()->all();
}

beforeEach(fn () => Carbon::setTestNow('2026-07-25 06:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('plans a sendable overdue customer and skips one that cannot be messaged', function () {
    [$b, $u] = autoBusiness();
    planCustomer($b, $u, 'Reachable');
    planCustomer($b, $u, 'No Phone', phone: null);
    $optedOut = planCustomer($b, $u, 'Opted Out');
    $optedOut->reminder_opt_out_at = now();
    $optedOut->save();

    $batch = planFor($b);

    expect($batch)->not->toBeNull();
    expect(plannedNames($b))->toBe(['Reachable']);
    expect($batch->planned_count)->toBe(1);
});

it('excludes a customer auto-reminded inside the cooldown but includes one beyond it', function () {
    [$b, $u] = autoBusiness(['reminder_cooldown_days' => 7]);
    $batch = ReminderBatch::create([
        'business_id' => $b->id, 'scheduled_for' => now()->subDays(30)->format('Y-m-d'),
        'status' => 'sent', 'planned_count' => 2,
    ]);

    priorReminder(planCustomer($b, $u, 'Recent'), $u, '3', $batch->id);
    priorReminder(planCustomer($b, $u, 'Stale'), $u, '8', $batch->id);

    planFor($b);

    expect(plannedNames($b))->toBe(['Stale']);
});

it('does not let a manual reminder block automation, since a human chose it', function () {
    [$b, $u] = autoBusiness(['reminder_cooldown_days' => 7]);
    // batch_id null => the owner tapped Remind themselves yesterday.
    priorReminder(planCustomer($b, $u, 'Manually Chased'), $u, '1', null);

    planFor($b);

    expect(plannedNames($b))->toBe(['Manually Chased']);
});

it('caps the run at the biggest debts and says why it stopped', function () {
    [$b, $u] = autoBusiness(['reminder_daily_cap' => 3]);
    foreach (['A' => '900.00', 'B' => '5000.00', 'C' => '1500.00', 'D' => '9000.00', 'E' => '700.00'] as $name => $total) {
        planCustomer($b, $u, $name, $total);
    }

    $batch = planFor($b);

    expect(plannedNames($b))->toBe(['B', 'C', 'D']);   // the three largest
    expect($batch->planned_count)->toBe(3);
    expect($batch->stopped_reason)->toBe('daily_cap');
});

it('leaves stopped_reason null when everyone fits under the cap', function () {
    [$b, $u] = autoBusiness(['reminder_daily_cap' => 25]);
    planCustomer($b, $u, 'Only One');

    expect(planFor($b)->stopped_reason)->toBeNull();
});

it('is idempotent for the day, so a double cron fire cannot double-message', function () {
    [$b, $u] = autoBusiness();
    planCustomer($b, $u, 'Ramesh');

    planFor($b);
    planFor($b);   // cron fired twice

    expect(ReminderBatch::where('business_id', $b->id)->count())->toBe(1);
    expect(ReminderLog::where('business_id', $b->id)->count())->toBe(1);
});

it('plans nothing for a tenant that never switched automation on', function () {
    $b = Business::factory()->create();          // default: auto disabled
    $u = User::factory()->create();
    planCustomer($b, $u, 'Ramesh');

    expect(planFor($b))->toBeNull();
    expect(ReminderLog::where('business_id', $b->id)->count())->toBe(0);
});

it('never plans another tenant\'s customers', function () {
    [$mine, $u] = autoBusiness();
    [$theirs] = autoBusiness();
    planCustomer($mine, $u, 'Mine');
    planCustomer($theirs, $u, 'Theirs');

    planFor($mine);

    expect(plannedNames($mine))->toBe(['Mine']);
});
