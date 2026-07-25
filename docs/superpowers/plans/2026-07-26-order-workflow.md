# Order Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A salesman takes an **order** offline; an owner/admin accepts it (adjusting qty or price) or rejects it; the salesman marks it packed then delivered — and **delivery creates the sale**, so nothing counts as money owed until goods arrive.

**Architecture:** Two new offline-synced tables (`orders`, `order_lines`), a pure `OrderStatus` rule unit, an `OrderWriter` mirroring `LedgerWriter`'s idempotent shape, four new outbox mutation types, one Blade accept screen and two phone screens. Delivery calls `LedgerWriter::createSale` reusing the **order's uuid**, so a replayed delivery can never double a khata.

**Tech Stack:** PHP 8.3 / Laravel 11, PostgreSQL (RLS), Pest; React + Dexie, Vitest (`fake-indexeddb`).

**Spec:** `docs/superpowers/specs/2026-07-26-order-workflow-design.md`

---

## Before you start

```bash
git checkout master && git pull
git checkout -b feat/order-workflow
```

- App root is `backend/`. Run every command from there.
- Postgres/PgBouncer/Redis must be running; if the suite cannot connect, ask the user to start them (WSL sudo — only they can).
- Record the baseline:

```bash
cd backend && php artisan test        # expect 715 passed
npx vitest run                        # expect 149 passed
```

### Conventions

- Money is a **scale-2 decimal string**; `bcadd`/`bcmul`/`bccomp`, never floats. In JS, integer paise via `toPaise()`.
- `created_by`, `total`, `line_total` are **never fillable** — stamped explicitly.
- Tests seed fixtures on the `pgsql_migrate` connection (bypasses RLS), then read through the tenant pin.
- Assertions **outside** a tenant-pinned closure must query `DB::connection('pgsql_migrate')->table(...)` — `BelongsToTenant` hides rows otherwise.
- Every tenant table gets RLS `ENABLE` + `FORCE` and an `_isolation` policy. Copy the `expenses` migration.

### Scope decisions locked from the spec (do not re-litigate)

- Delivery creates the sale. Orders and sales are separate tables; **no existing money query changes**.
- Orders replace direct sales **in the app only** — the server keeps accepting the `sale` mutation and `POST /api/v1/sales`.
- Full delivery only: one order → one sale.
- The sale reuses the **order's uuid**; its `sale_date` is the **delivery date**; its `created_by` is **whoever delivered**.
- Acceptance is the only online step. Packing is gated on having synced the acceptance.
- Packing does **not** touch stock.
- No record is kept of what the owner changed at acceptance (accepted limitation).

### One tightening this plan makes, beyond the spec

The spec says a transition must "increase the rank". That would permit `accepted → delivered`, skipping `packed`. This plan requires the linear path to advance by **exactly one rank**, so a delivered order was always packed first — otherwise the packing state means nothing. Terminal entries (`rejected`, `cancelled`) are separate rules, unaffected.

---

## File structure

**Create:**
- `app/Orders/OrderStatus.php` — the transition rules (pure).
- `tests/Unit/OrderStatusTest.php`
- `database/migrations/2026_07_26_000001_create_orders_tables.php`
- `app/Models/Order.php`, `app/Models/OrderLine.php`
- `app/Services/OrderWriter.php`
- `tests/Feature/Orders/OrderWriterTest.php`
- `tests/Feature/Orders/OrderSyncTest.php`
- `app/Http/Controllers/Web/OrderController.php`
- `resources/views/orders/index.blade.php`
- `lang/en/orders.php`, `lang/hi/orders.php`
- `tests/Feature/Web/OrdersTest.php`
- `resources/js/offline/orders.js`, `resources/js/offline/orders.test.js`
- `resources/js/screens/Orders.jsx`

**Modify:**
- `app/Http/Controllers/Api/V1/SyncController.php` — 4 mutation types, pull payload.
- `app/Export/TenantEraser.php`, `app/Export/TenantExporter.php` — register both tables.
- `routes/web.php` — the owner order routes.
- `resources/js/offline/db.js` — Dexie v6 stores.
- `resources/js/offline/sync.js` — `PULL_TABLES`.
- `resources/js/offline/outbox.js` — `PUSHABLE_TYPES`.
- `resources/js/screens/Forms.jsx` — `RecordSale` becomes `RecordOrder`.
- `resources/js/main.jsx` — routes, state, save handlers.
- `docs/ui-backlog.md` — `F-13`.

---

## Task 1: `OrderStatus` — the transition rules (pure, TDD)

**Files:** Create `app/Orders/OrderStatus.php`, `tests/Unit/OrderStatusTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/OrderStatusTest.php

use App\Orders\OrderStatus;

it('knows which states are terminal', function () {
    expect(OrderStatus::isTerminal(OrderStatus::DELIVERED))->toBeTrue();
    expect(OrderStatus::isTerminal(OrderStatus::REJECTED))->toBeTrue();
    expect(OrderStatus::isTerminal(OrderStatus::CANCELLED))->toBeTrue();

    expect(OrderStatus::isTerminal(OrderStatus::PENDING))->toBeFalse();
    expect(OrderStatus::isTerminal(OrderStatus::ACCEPTED))->toBeFalse();
    expect(OrderStatus::isTerminal(OrderStatus::PACKED))->toBeFalse();
});

it('allows the linear path one step at a time', function () {
    expect(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::ACCEPTED))->toBeTrue();
    expect(OrderStatus::canTransition(OrderStatus::ACCEPTED, OrderStatus::PACKED))->toBeTrue();
    expect(OrderStatus::canTransition(OrderStatus::PACKED, OrderStatus::DELIVERED))->toBeTrue();
});

it('refuses to skip a step, so a delivered order was always packed', function () {
    expect(OrderStatus::canTransition(OrderStatus::ACCEPTED, OrderStatus::DELIVERED))->toBeFalse();
    expect(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::PACKED))->toBeFalse();
});

it('refuses to move backwards, so a replayed push cannot rewind an order', function () {
    expect(OrderStatus::canTransition(OrderStatus::PACKED, OrderStatus::ACCEPTED))->toBeFalse();
    expect(OrderStatus::canTransition(OrderStatus::DELIVERED, OrderStatus::PACKED))->toBeFalse();
});

it('refuses to stay put — a repeat is handled by the caller, not a transition', function () {
    expect(OrderStatus::canTransition(OrderStatus::PACKED, OrderStatus::PACKED))->toBeFalse();
});

it('allows rejection only from pending', function () {
    expect(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::REJECTED))->toBeTrue();
    expect(OrderStatus::canTransition(OrderStatus::ACCEPTED, OrderStatus::REJECTED))->toBeFalse();
    expect(OrderStatus::canTransition(OrderStatus::PACKED, OrderStatus::REJECTED))->toBeFalse();
});

it('allows cancellation from any non-terminal state', function () {
    expect(OrderStatus::canTransition(OrderStatus::PENDING, OrderStatus::CANCELLED))->toBeTrue();
    expect(OrderStatus::canTransition(OrderStatus::ACCEPTED, OrderStatus::CANCELLED))->toBeTrue();
    expect(OrderStatus::canTransition(OrderStatus::PACKED, OrderStatus::CANCELLED))->toBeTrue();
});

it('never leaves a terminal state, whatever the target', function () {
    foreach ([OrderStatus::DELIVERED, OrderStatus::REJECTED, OrderStatus::CANCELLED] as $terminal) {
        foreach (OrderStatus::all() as $target) {
            expect(OrderStatus::canTransition($terminal, $target))->toBeFalse();
        }
    }
});

it('rejects an unknown status rather than guessing', function () {
    expect(OrderStatus::canTransition('banana', OrderStatus::ACCEPTED))->toBeFalse();
    expect(OrderStatus::canTransition(OrderStatus::PENDING, 'banana'))->toBeFalse();
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Unit/OrderStatusTest.php`
Expected: FAIL — class `App\Orders\OrderStatus` not found.

- [ ] **Step 3: Implement `app/Orders/OrderStatus.php`**

```php
<?php
// app/Orders/OrderStatus.php

namespace App\Orders;

/**
 * What an order may become next.
 *
 * Pure and DB-free: this is the rule every other part of the workflow leans on,
 * and a phone that has been offline for days will push transitions out of order
 * or twice. Forward-only, one step at a time, and never out of a terminal
 * state — the same discipline reminder_logs uses for delivery status.
 */
final class OrderStatus
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const PACKED = 'packed';

    public const DELIVERED = 'delivered';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** The linear path. Rank order is the only order these may be walked in. */
    private const RANK = [
        self::PENDING => 0,
        self::ACCEPTED => 1,
        self::PACKED => 2,
        self::DELIVERED => 3,
    ];

    private const TERMINAL = [self::DELIVERED, self::REJECTED, self::CANCELLED];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PENDING, self::ACCEPTED, self::PACKED, self::DELIVERED, self::REJECTED, self::CANCELLED];
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        // An order that is delivered, rejected or cancelled is finished. This
        // is what stops a late push from the field resurrecting it.
        if (self::isTerminal($from)) {
            return false;
        }

        if (! in_array($from, self::all(), true) || ! in_array($to, self::all(), true)) {
            return false;
        }

        // Cancellation is available from anywhere still open — a shop refusing
        // the goods at the door is the case this exists for.
        if ($to === self::CANCELLED) {
            return true;
        }

        // Rejection is the owner declining an order at acceptance, so it is
        // only reachable before acceptance.
        if ($to === self::REJECTED) {
            return $from === self::PENDING;
        }

        // Exactly one step forward. Skipping would let an order be delivered
        // that was never packed, which makes the packing state meaningless.
        return isset(self::RANK[$from], self::RANK[$to])
            && self::RANK[$to] === self::RANK[$from] + 1;
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/pest tests/Unit/OrderStatusTest.php`
Expected: PASS (9 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Orders/OrderStatus.php tests/Unit/OrderStatusTest.php
git commit -m "feat: add OrderStatus, the order workflow's transition rules"
```

---

## Task 2: Schema and models

**Files:** Create the migration and both models; modify the DPDP registries.

- [ ] **Step 1: Write the migration** — `database/migrations/2026_07_26_000001_create_orders_tables.php`

```php
<?php
// database/migrations/2026_07_26_000001_create_orders_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders: what a shop asked for, before anyone owes anything.
 *
 * Separate from `sales` on purpose. A sale row IS a khata entry, and every
 * money figure in the app is built on that; an order is the stage before, so
 * outstanding, cash flow, COGS, invoicing and reminders need no change at all.
 *
 * Offline-synced (sync_seq + version) because orders are taken in villages with
 * no signal. `uuid` is the client's idempotency key — and the sale created on
 * delivery reuses it, so a replayed delivery cannot double a khata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrate')->create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->uuid('uuid');
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->date('order_date');
            $table->string('status', 12)->default('pending');
            $table->decimal('total', 12, 2);

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('accepted_by')->nullable()->constrained('users');
            $table->timestamp('accepted_at')->nullable();
            // Why an order was rejected or cancelled. Optional: an unexplained
            // rejection is unhelpful, not invalid.
            $table->string('status_note', 255)->nullable();
            // What it became. Null until delivered.
            $table->foreignUuid('sale_id')->nullable()->constrained('sales');

            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            $table->unique(['business_id', 'uuid']);
            $table->index(['business_id', 'sync_seq']);   // delta pull
            $table->index(['business_id', 'status']);     // the owner's pending list
        });

        Schema::connection('pgsql_migrate')->create('order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('product_pack_id')->constrained('product_packs');
            $table->integer('qty');
            $table->decimal('rate', 10, 2);
            $table->decimal('line_total', 12, 2);
            // No list_rate: that is authored server-side when the sale is
            // created, so an order has no business carrying it.
            $table->unsignedInteger('version')->default(1);
            $table->bigInteger('sync_seq');
            $table->timestamps();

            $table->index(['business_id', 'order_id']);
            $table->index(['business_id', 'sync_seq']);
        });

        foreach (['orders', 'order_lines'] as $table) {
            DB::connection('pgsql_migrate')->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::connection('pgsql_migrate')->statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::connection('pgsql_migrate')->statement(
                "CREATE POLICY {$table}_isolation ON {$table}
                 USING (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)
                 WITH CHECK (business_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid)"
            );
        }
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrate')->dropIfExists('order_lines');
        Schema::connection('pgsql_migrate')->dropIfExists('orders');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `2026_07_26_000001_create_orders_tables ... DONE`

- [ ] **Step 3: Create `app/Models/Order.php`**

```php
<?php
// app/Models/Order.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a shop asked for. Becomes a Sale when delivered — see OrderWriter.
 *
 * created_by, total, status, accepted_by, accepted_at, status_note and sale_id
 * are absent from $fillable: all are stamped by OrderWriter or the accept
 * screen, never taken from a client payload.
 */
class Order extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    protected $fillable = ['business_id', 'uuid', 'customer_id', 'order_date'];

    protected $casts = [
        'order_date' => 'date',
        'accepted_at' => 'datetime',
        'total' => 'decimal:2',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
```

- [ ] **Step 4: Create `app/Models/OrderLine.php`**

```php
<?php
// app/Models/OrderLine.php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasSyncSequence;
use App\Traits\HasVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an order. line_total is stamped, never filled. */
class OrderLine extends Model
{
    use BelongsToTenant, HasFactory, HasSyncSequence, HasUuids, HasVersion;

    protected $fillable = ['business_id', 'order_id', 'product_pack_id', 'qty', 'rate'];

    protected $casts = [
        'qty' => 'integer',
        'rate' => 'decimal:2',
        'line_total' => 'decimal:2',
        'version' => 'integer',
        'sync_seq' => 'integer',
    ];

    /** @return BelongsTo<ProductPack, $this> */
    public function productPack(): BelongsTo
    {
        return $this->belongsTo(ProductPack::class);
    }
}
```

- [ ] **Step 5: Register both tables for DPDP**

In `app/Export/TenantEraser.php`, add to `DELETE_ORDER` **before** `'sale_lines'` (children first; `orders.sale_id` references `sales`, so orders must go before sales):

```php
        'order_lines',
        'orders',
```

In `app/Export/TenantExporter.php`, add to its table list:

```php
        'orders',
        'order_lines',
```

- [ ] **Step 6: Run the DPDP tests**

Run: `./vendor/bin/pest tests/Feature/Export/`
Expected: PASS (19). The "covers every tenant-owned table" test is what proves the registration; if it fails, a table is missing from one of the two lists.

- [ ] **Step 7: Commit**

```bash
git add database/migrations app/Models/Order.php app/Models/OrderLine.php app/Export
git commit -m "feat: add orders and order_lines tables"
```

---

## Task 3: `OrderWriter::createOrder` (TDD)

**Files:** Create `app/Services/OrderWriter.php`, `tests/Feature/Orders/OrderWriterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Orders/OrderWriterTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Order;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Orders\OrderStatus;
use App\Services\KhataService;
use App\Services\OrderWriter;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A shop, a salesman, a customer and one pack at 90.00 with a 70.00 cost floor. */
function orderSetup(string $role = 'salesman'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);

    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders', 'opening_balance' => '0.00',
    ]);

    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $size = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $pack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '90.00',
        'default_cost_price' => '70.00',
    ]);

    return [$business, $user, $customer, $pack];
}

/** Run inside the tenant pin, as the sync push does in production. */
function inOrderTenant(Business $b, User $u, callable $fn, string $role = 'salesman'): mixed
{
    return DB::transaction(function () use ($b, $u, $fn, $role) {
        TenantContext::switchTo($b->id);
        app()->bind('tenant.id', fn () => $b->id);
        app()->bind('tenant.user_id', fn () => $u->id);
        app()->bind('tenant.role', fn () => $role);

        return $fn();
    });
}

function orderPayload(Customer $c, ProductPack $pack, array $over = []): array
{
    return array_merge([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id,
        'order_date' => '2026-07-26',
        'lines' => [['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '85.00']],
    ], $over);
}

it('creates a pending order with a total from its lines', function () {
    [$b, $u, $c, $pack] = orderSetup();

    [$order, $created] = inOrderTenant($b, $u, fn () => app(OrderWriter::class)
        ->createOrder(orderPayload($c, $pack)));

    expect($created)->toBeTrue();
    expect($order->status)->toBe(OrderStatus::PENDING);
    expect((string) $order->total)->toBe('170.00');   // 2 x 85.00
    expect($order->created_by)->toBe($u->id);
});

it('does not move the khata — an order is not money owed', function () {
    [$b, $u, $c, $pack] = orderSetup();

    inOrderTenant($b, $u, fn () => app(OrderWriter::class)->createOrder(orderPayload($c, $pack)));

    $outstanding = inOrderTenant($b, $u, fn () => app(KhataService::class)
        ->outstandingFor(Customer::findOrFail($c->id)));

    // The entire point of the design: nothing is owed until goods arrive.
    expect($outstanding)->toBe('0.00');
    expect(DB::connection('pgsql_migrate')->table('sales')->where('business_id', $b->id)->count())->toBe(0);
});

it('is idempotent by uuid, so a replayed push creates one order', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $payload = orderPayload($c, $pack);

    inOrderTenant($b, $u, fn () => app(OrderWriter::class)->createOrder($payload));
    [$order, $created] = inOrderTenant($b, $u, fn () => app(OrderWriter::class)->createOrder($payload));

    expect($created)->toBeFalse();
    expect(DB::connection('pgsql_migrate')->table('orders')->where('business_id', $b->id)->count())->toBe(1);
    expect($order->id)->not->toBeNull();
});

it('refuses a line below the pack cost floor, exactly as a sale would', function () {
    [$b, $u, $c, $pack] = orderSetup();

    $call = fn () => inOrderTenant($b, $u, fn () => app(OrderWriter::class)->createOrder(
        orderPayload($c, $pack, ['lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '69.99']]])
    ));

    expect($call)->toThrow(Illuminate\Validation\ValidationException::class);
    expect(DB::connection('pgsql_migrate')->table('orders')->where('business_id', $b->id)->count())->toBe(0);
});

it('refuses another tenant\'s customer', function () {
    [$b, $u, , $pack] = orderSetup();
    [$other] = orderSetup();
    $theirCustomer = Customer::on('pgsql_migrate')->create([
        'business_id' => $other->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Not Yours', 'opening_balance' => '0.00',
    ]);

    $call = fn () => inOrderTenant($b, $u, fn () => app(OrderWriter::class)->createOrder([
        'uuid' => (string) Str::uuid(), 'customer_id' => $theirCustomer->id,
        'order_date' => '2026-07-26',
        'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '85.00']],
    ]));

    // Invisible under RLS, so it 404s rather than leaking that it exists.
    expect($call)->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Orders/OrderWriterTest.php`
Expected: FAIL — class `App\Services\OrderWriter` not found.

- [ ] **Step 3: Implement `createOrder` in `app/Services/OrderWriter.php`**

```php
<?php
// app/Services/OrderWriter.php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductPack;
use App\Orders\OrderStatus;
use App\Pricing\PriceFloor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one home for order writes, mirroring LedgerWriter's shape: every method
 * is idempotent by (business_id, uuid), stamps business_id and created_by from
 * the tenant pin rather than the payload, and returns [model, bool $created] so
 * the caller can map applied vs duplicate.
 *
 * An order is NOT money. Nothing here touches the khata — that happens exactly
 * once, in deliver(), which routes through LedgerWriter::createSale.
 */
class OrderWriter
{
    /** @return array<string, array<int, mixed>> */
    public static function rulesForOrder(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'customer_id' => ['required', 'uuid'],
            'order_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_pack_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'integer', 'not_in:0'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }

    /** @return array{0: Order, 1: bool} */
    public function createOrder(array $data): array
    {
        $existing = Order::where('uuid', $data['uuid'])->first();
        if ($existing) {
            return [$existing->load('lines'), false];
        }

        // findOrFail under RLS: another tenant's customer is invisible → 404.
        $customer = Customer::findOrFail($data['customer_id']);

        $packIds = array_column($data['lines'], 'product_pack_id');
        $packs = ProductPack::with(['product', 'packSize'])->whereIn('id', $packIds)->get()->keyBy('id');

        $order = DB::transaction(function () use ($data, $customer, $packs) {
            $lines = [];
            $total = '0.00';

            foreach ($data['lines'] as $line) {
                $pack = $packs[$line['product_pack_id']] ?? null;

                if ($pack === null) {
                    throw (new ModelNotFoundException)->setModel(ProductPack::class, [$line['product_pack_id']]);
                }

                $rate = isset($line['rate'])
                    ? bcadd((string) $line['rate'], '0', 2)
                    : bcadd((string) $pack->default_sell_price, '0', 2);

                // The same floor a sale is held to. An order below cost would
                // only be refused later at delivery, after the shop was told.
                $floor = PriceFloor::for($pack);
                if ($floor !== null && bccomp($rate, $floor, 2) < 0) {
                    throw ValidationException::withMessages([
                        'lines' => __('sales.rate_below_floor', [
                            'product' => $pack->product?->name_en ?: $pack->product?->name_hi ?: 'this product',
                            'floor' => $floor,
                        ]),
                    ]);
                }

                $lineTotal = bcmul($rate, (string) $line['qty'], 2);
                $lines[] = [
                    'product_pack_id' => $pack->id,
                    'qty' => $line['qty'],
                    'rate' => $rate,
                    'line_total' => $lineTotal,
                ];
                $total = bcadd($total, $lineTotal, 2);
            }

            $order = new Order([
                'business_id' => app('tenant.id'),
                'uuid' => $data['uuid'],
                'customer_id' => $customer->id,
                'order_date' => $data['order_date'],
            ]);
            $order->status = OrderStatus::PENDING;
            $order->total = $total;
            $order->created_by = app('tenant.user_id');
            $order->save();

            foreach ($lines as $l) {
                $orderLine = new OrderLine([
                    'business_id' => app('tenant.id'),
                    'order_id' => $order->id,
                    'product_pack_id' => $l['product_pack_id'],
                    'qty' => $l['qty'],
                    'rate' => $l['rate'],
                ]);
                $orderLine->line_total = $l['line_total'];
                $orderLine->save();
            }

            return $order;
        });

        return [$order->load('lines'), true];
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `./vendor/bin/pest tests/Feature/Orders/OrderWriterTest.php`
Expected: PASS (5 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Services/OrderWriter.php tests/Feature/Orders/OrderWriterTest.php
git commit -m "feat: add OrderWriter::createOrder — an order, not a sale"
```

---

## Task 4: `pack` and `cancel` (TDD)

**Files:** Modify `app/Services/OrderWriter.php`; extend `tests/Feature/Orders/OrderWriterTest.php`

- [ ] **Step 1: Write the failing tests (append to the same file)**

```php
/** Create an order and push it to $status through the writer's own methods. */
function orderAt(Business $b, User $u, Customer $c, ProductPack $pack, string $status): Order
{
    [$order] = inOrderTenant($b, $u, fn () => app(OrderWriter::class)->createOrder(orderPayload($c, $pack)));

    if ($status === OrderStatus::PENDING) {
        return $order;
    }

    // Acceptance is the online step and has no writer method — the Blade screen
    // does it. Set it directly so the field transitions can be exercised.
    DB::connection('pgsql_migrate')->table('orders')->where('id', $order->id)
        ->update(['status' => OrderStatus::ACCEPTED, 'accepted_by' => $u->id, 'accepted_at' => now()]);

    if ($status === OrderStatus::ACCEPTED) {
        return $order->fresh();
    }

    inOrderTenant($b, $u, fn () => app(OrderWriter::class)->pack($order->uuid));

    return $order->fresh();
}

it('packs an accepted order', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $order = orderAt($b, $u, $c, $pack, OrderStatus::ACCEPTED);

    [$packed, $changed] = inOrderTenant($b, $u, fn () => app(OrderWriter::class)->pack($order->uuid));

    expect($changed)->toBeTrue();
    expect($packed->status)->toBe(OrderStatus::PACKED);
});

it('treats a replayed pack as a duplicate rather than an error', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $order = orderAt($b, $u, $c, $pack, OrderStatus::PACKED);

    [, $changed] = inOrderTenant($b, $u, fn () => app(OrderWriter::class)->pack($order->uuid));

    // Same state, no complaint: the phone resent its outbox.
    expect($changed)->toBeFalse();
});

it('refuses to pack an order the owner has not accepted yet', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $order = orderAt($b, $u, $c, $pack, OrderStatus::PENDING);

    $call = fn () => inOrderTenant($b, $u, fn () => app(OrderWriter::class)->pack($order->uuid));

    expect($call)->toThrow(Illuminate\Validation\ValidationException::class);
    expect($order->fresh()->status)->toBe(OrderStatus::PENDING);
});

it('cancels an order at any open stage, keeping the reason', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $order = orderAt($b, $u, $c, $pack, OrderStatus::PACKED);

    [$cancelled] = inOrderTenant($b, $u, fn () => app(OrderWriter::class)
        ->cancel($order->uuid, 'Shop refused delivery'));

    expect($cancelled->status)->toBe(OrderStatus::CANCELLED);
    expect($cancelled->status_note)->toBe('Shop refused delivery');
});

it('refuses to cancel an order that is already terminal', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $order = orderAt($b, $u, $c, $pack, OrderStatus::PENDING);
    inOrderTenant($b, $u, fn () => app(OrderWriter::class)->cancel($order->uuid, 'changed mind'));

    $call = fn () => inOrderTenant($b, $u, fn () => app(OrderWriter::class)->cancel($order->uuid, 'again'));

    expect($call)->toThrow(Illuminate\Validation\ValidationException::class);
});
```

- [ ] **Step 2: Run and watch them fail**

Run: `./vendor/bin/pest tests/Feature/Orders/OrderWriterTest.php`
Expected: FAIL — `pack()` / `cancel()` do not exist.

- [ ] **Step 3: Add the transition helper and both methods to `OrderWriter`**

```php
    /**
     * Move an order to $to, or report that it is already there.
     *
     * A repeat of the same state is a duplicate, not an error — the phone
     * resent its outbox. An illegal move (skipping a step, going backwards,
     * touching a terminal order) throws, so the sync push parks that one
     * mutation and the batch continues.
     *
     * @return array{0: Order, 1: bool}
     */
    private function transition(string $orderUuid, string $to, ?string $note = null): array
    {
        return DB::transaction(function () use ($orderUuid, $to, $note) {
            $order = Order::where('uuid', $orderUuid)->first();

            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [$orderUuid]);
            }

            if ($order->status === $to) {
                return [$order, false];
            }

            if (! OrderStatus::canTransition($order->status, $to)) {
                throw ValidationException::withMessages([
                    'status' => __('orders.illegal_transition', ['from' => $order->status, 'to' => $to]),
                ]);
            }

            $order->status = $to;
            if ($note !== null) {
                $order->status_note = $note;
            }
            $order->save();

            return [$order, true];
        });
    }

    /** @return array{0: Order, 1: bool} */
    public function pack(string $orderUuid): array
    {
        return $this->transition($orderUuid, OrderStatus::PACKED);
    }

    /** @return array{0: Order, 1: bool} */
    public function cancel(string $orderUuid, ?string $note = null): array
    {
        return $this->transition($orderUuid, OrderStatus::CANCELLED, $note);
    }
```

- [ ] **Step 4: Add the message key**

Create `lang/en/orders.php`:

```php
<?php

return [
    'illegal_transition' => 'This order cannot go from :from to :to.',
];
```

Create `lang/hi/orders.php`:

```php
<?php

return [
    'illegal_transition' => 'यह ऑर्डर :from से :to नहीं किया जा सकता।',
];
```

- [ ] **Step 5: Run and pass**

Run: `./vendor/bin/pest tests/Feature/Orders/OrderWriterTest.php`
Expected: PASS (10 passing).

- [ ] **Step 6: Commit**

```bash
git add app/Services/OrderWriter.php lang/en/orders.php lang/hi/orders.php tests/Feature/Orders/OrderWriterTest.php
git commit -m "feat: add order pack and cancel transitions"
```

---

## Task 5: `deliver` — the order becomes a sale (TDD)

**Files:** Modify `app/Services/OrderWriter.php`; extend `tests/Feature/Orders/OrderWriterTest.php`

- [ ] **Step 1: Write the failing tests (append)**

```php
it('creates the sale on delivery, dated the delivery day and credited to the deliverer', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $order = orderAt($b, $u, $c, $pack, OrderStatus::PACKED);

    Illuminate\Support\Carbon::setTestNow('2026-07-29');
    [$delivered] = inOrderTenant($b, $u, fn () => app(OrderWriter::class)->deliver($order->uuid));
    Illuminate\Support\Carbon::setTestNow();

    $sale = DB::connection('pgsql_migrate')->table('sales')->where('business_id', $b->id)->sole();

    expect($delivered->status)->toBe(OrderStatus::DELIVERED);
    expect($delivered->sale_id)->toBe($sale->id);
    // The money event is the goods arriving, not the order being taken.
    expect(substr((string) $sale->sale_date, 0, 10))->toBe('2026-07-29');
    expect($sale->created_by)->toBe($u->id);
    expect((string) $sale->total)->toBe('170.00');
    // The sale carries the order's uuid — that is the idempotency guarantee.
    expect($sale->uuid)->toBe($order->uuid);
});

it('moves the khata only on delivery', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $order = orderAt($b, $u, $c, $pack, OrderStatus::PACKED);

    $before = inOrderTenant($b, $u, fn () => app(KhataService::class)
        ->outstandingFor(Customer::findOrFail($c->id)));
    inOrderTenant($b, $u, fn () => app(OrderWriter::class)->deliver($order->uuid));
    $after = inOrderTenant($b, $u, fn () => app(KhataService::class)
        ->outstandingFor(Customer::findOrFail($c->id)));

    expect($before)->toBe('0.00');
    expect($after)->toBe('170.00');
});

it('never creates a second sale when delivery is replayed', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $order = orderAt($b, $u, $c, $pack, OrderStatus::PACKED);

    inOrderTenant($b, $u, fn () => app(OrderWriter::class)->deliver($order->uuid));
    [, $changed] = inOrderTenant($b, $u, fn () => app(OrderWriter::class)->deliver($order->uuid));

    expect($changed)->toBeFalse();
    expect(DB::connection('pgsql_migrate')->table('sales')->where('business_id', $b->id)->count())->toBe(1);
});

it('refuses to deliver an order the owner rejected while the phone was offline', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $order = orderAt($b, $u, $c, $pack, OrderStatus::PENDING);
    DB::connection('pgsql_migrate')->table('orders')->where('id', $order->id)
        ->update(['status' => OrderStatus::REJECTED]);

    $call = fn () => inOrderTenant($b, $u, fn () => app(OrderWriter::class)->deliver($order->uuid));

    expect($call)->toThrow(Illuminate\Validation\ValidationException::class);
    // No sale: the owner declined it, and the field does not get to overrule that.
    expect(DB::connection('pgsql_migrate')->table('sales')->where('business_id', $b->id)->count())->toBe(0);
});

it('refuses to deliver an order that was never packed', function () {
    [$b, $u, $c, $pack] = orderSetup();
    $order = orderAt($b, $u, $c, $pack, OrderStatus::ACCEPTED);

    $call = fn () => inOrderTenant($b, $u, fn () => app(OrderWriter::class)->deliver($order->uuid));

    expect($call)->toThrow(Illuminate\Validation\ValidationException::class);
});
```

- [ ] **Step 2: Run and watch them fail**

Run: `./vendor/bin/pest tests/Feature/Orders/OrderWriterTest.php`
Expected: FAIL — `deliver()` does not exist.

- [ ] **Step 3: Implement `deliver`**

Add to `OrderWriter`, and inject `LedgerWriter` via the constructor:

```php
    public function __construct(private readonly LedgerWriter $ledger) {}

    /**
     * Delivery is the money event: it creates the sale.
     *
     * The sale reuses the ORDER's uuid. createSale is already idempotent by
     * (business_id, uuid), so a replayed delivery returns the existing sale
     * instead of doubling a customer's khata — the guarantee comes free from
     * machinery that is already correct.
     *
     * sale_date is today, not the order date: the sale records goods arriving.
     * created_by is stamped by LedgerWriter from the tenant pin, so it is
     * whoever delivered, not whoever took the order.
     *
     * @return array{0: Order, 1: bool}
     */
    public function deliver(string $orderUuid): array
    {
        return DB::transaction(function () use ($orderUuid) {
            $order = Order::with('lines')->where('uuid', $orderUuid)->first();

            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [$orderUuid]);
            }

            if ($order->status === OrderStatus::DELIVERED) {
                return [$order, false];
            }

            if (! OrderStatus::canTransition($order->status, OrderStatus::DELIVERED)) {
                throw ValidationException::withMessages([
                    'status' => __('orders.illegal_transition', [
                        'from' => $order->status, 'to' => OrderStatus::DELIVERED,
                    ]),
                ]);
            }

            [$sale] = $this->ledger->createSale([
                'uuid' => $order->uuid,
                'customer_id' => $order->customer_id,
                'sale_date' => now()->toDateString(),
                'lines' => $order->lines->map(fn (OrderLine $l) => [
                    'product_pack_id' => $l->product_pack_id,
                    'qty' => $l->qty,
                    'rate' => (string) $l->rate,
                ])->all(),
            ]);

            $order->status = OrderStatus::DELIVERED;
            $order->sale_id = $sale->id;
            $order->save();

            return [$order, true];
        });
    }
```

- [ ] **Step 4: Run and pass**

Run: `./vendor/bin/pest tests/Feature/Orders/OrderWriterTest.php`
Expected: PASS (15 passing).

- [ ] **Step 5: Commit**

```bash
git add app/Services/OrderWriter.php tests/Feature/Orders/OrderWriterTest.php
git commit -m "feat: delivering an order creates the sale"
```

---

## Task 6: Sync — four mutation types and pull visibility (TDD)

**Files:** Modify `app/Http/Controllers/Api/V1/SyncController.php`; create `tests/Feature/Orders/OrderSyncTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Orders/OrderSyncTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @return array{0: Business, 1: User, 2: string, 3: Customer, 4: ProductPack} */
function orderSyncSetup(string $role = 'salesman'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);
    $token = (new TokenService())->issue($user, $membership);

    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders', 'opening_balance' => '0.00',
    ]);
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $size = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $pack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '90.00',
    ]);

    return [$business, $user, $token, $customer, $pack];
}

function pushOrder(string $token, array $mutations)
{
    return test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/sync/push', ['mutations' => $mutations]);
}

it('accepts an order mutation and creates a pending order', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup();
    $uuid = (string) Str::uuid();

    pushOrder($token, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => $uuid,
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 2, 'rate' => '85.00']],
        ],
    ]])->assertOk()->assertJsonPath('results.0.status', 'applied');

    $order = DB::connection('pgsql_migrate')->table('orders')->where('business_id', $business->id)->sole();
    expect($order->status)->toBe('pending');
    expect((string) $order->total)->toBe('170.00');
});

it('walks an order through pack and deliver from the field', function () {
    [$business, $user, $token, $customer, $pack] = orderSyncSetup();
    $uuid = (string) Str::uuid();

    pushOrder($token, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => $uuid,
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00']],
        ],
    ]])->assertOk();

    // Acceptance is the online step; do it directly, as the Blade screen would.
    DB::connection('pgsql_migrate')->table('orders')->where('uuid', $uuid)
        ->update(['status' => 'accepted', 'accepted_by' => $user->id, 'accepted_at' => now()]);

    pushOrder($token, [[
        'type' => 'order_pack', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'payload' => ['order_uuid' => $uuid],
    ]])->assertOk()->assertJsonPath('results.0.status', 'applied');

    pushOrder($token, [[
        'type' => 'order_deliver', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'payload' => ['order_uuid' => $uuid],
    ]])->assertOk()->assertJsonPath('results.0.status', 'applied');

    expect(DB::connection('pgsql_migrate')->table('orders')->where('uuid', $uuid)->value('status'))
        ->toBe('delivered');
    expect(DB::connection('pgsql_migrate')->table('sales')->where('business_id', $business->id)->count())
        ->toBe(1);
});

it('parks a deliver for a rejected order without killing the batch', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup();
    $bad = (string) Str::uuid();
    $good = (string) Str::uuid();

    foreach ([$bad, $good] as $uuid) {
        pushOrder($token, [[
            'type' => 'order', 'tenant_id' => $business->id, 'uuid' => $uuid,
            'payload' => [
                'customer_id' => $customer->id, 'order_date' => '2026-07-26',
                'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00']],
            ],
        ]])->assertOk();
    }

    // The owner rejected one while the phone was offline; the other is fine.
    DB::connection('pgsql_migrate')->table('orders')->where('uuid', $bad)->update(['status' => 'rejected']);
    DB::connection('pgsql_migrate')->table('orders')->where('uuid', $good)->update(['status' => 'packed']);

    $response = pushOrder($token, [
        ['type' => 'order_deliver', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
         'payload' => ['order_uuid' => $bad]],
        ['type' => 'order_deliver', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
         'payload' => ['order_uuid' => $good]],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('rejected');
    expect($response->json('results.0.reason'))->toBe('invalid');
    expect($response->json('results.1.status'))->toBe('applied');

    // Exactly one sale: the rejected order made none.
    expect(DB::connection('pgsql_migrate')->table('sales')->where('business_id', $business->id)->count())
        ->toBe(1);
});

it('forbids an accountant from taking an order, as it forbids them a sale', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup('accountant');

    pushOrder($token, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00']],
        ],
    ]])->assertOk()->assertJsonPath('results.0.reason', 'forbidden');

    expect(DB::connection('pgsql_migrate')->table('orders')->where('business_id', $business->id)->count())
        ->toBe(0);
});

it('streams a salesman only the orders they took', function () {
    [$business, , $token, $customer, $pack] = orderSyncSetup();
    $mine = (string) Str::uuid();

    pushOrder($token, [[
        'type' => 'order', 'tenant_id' => $business->id, 'uuid' => $mine,
        'payload' => [
            'customer_id' => $customer->id, 'order_date' => '2026-07-26',
            'lines' => [['product_pack_id' => $pack->id, 'qty' => 1, 'rate' => '90.00']],
        ],
    ]])->assertOk();

    // Another salesman's order in the same shop.
    $other = User::factory()->create();
    Membership::on('pgsql_migrate')->create([
        'user_id' => $other->id, 'business_id' => $business->id, 'role' => 'salesman',
    ]);
    DB::connection('pgsql_migrate')->table('orders')->insert([
        'id' => (string) Str::uuid(), 'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'order_date' => '2026-07-26', 'status' => 'pending',
        'total' => '90.00', 'created_by' => $other->id, 'sync_seq' => 999999,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/sync/pull?since=0')->assertOk();

    expect($response->json('orders'))->toHaveCount(1);
    expect($response->json('orders.0.uuid'))->toBe($mine);
});
```

- [ ] **Step 2: Run and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Orders/OrderSyncTest.php`
Expected: FAIL — the push validator rejects `type: order` because it is not in `Rule::in([...])`.

- [ ] **Step 3: Extend the push validator in `SyncController::push()`**

```php
            'mutations.*.type' => ['required', Rule::in([
                'customer', 'sale', 'payment',
                'order', 'order_pack', 'order_deliver', 'order_cancel',
            ])],
```

- [ ] **Step 4: Extend `apply()`, `rulesFor()` and `roleAllows()`**

`push()` currently type-hints `LedgerWriter $writer`; add `OrderWriter $orders` alongside it and pass it through to `apply()`.

```php
    private function apply(LedgerWriter $writer, OrderWriter $orders, string $type, array $data): array
    {
        return match ($type) {
            'customer' => $writer->createCustomer($data),
            'sale' => $writer->createSale($data),
            'payment' => $writer->recordPayment($data),
            'order' => $orders->createOrder($data),
            // The envelope uuid is the mutation's own id; the ORDER is named in
            // the payload, so a retried pack and a fresh one both find it.
            'order_pack' => $orders->pack($data['order_uuid']),
            'order_deliver' => $orders->deliver($data['order_uuid']),
            'order_cancel' => $orders->cancel($data['order_uuid'], $data['status_note'] ?? null),
        };
    }

    private function rulesFor(string $type): array
    {
        return match ($type) {
            'customer' => LedgerWriter::rulesForCustomer(),
            'sale' => LedgerWriter::rulesForSale(),
            'payment' => LedgerWriter::rulesForPayment(),
            'order' => OrderWriter::rulesForOrder(),
            'order_pack', 'order_deliver' => ['order_uuid' => ['required', 'uuid']],
            'order_cancel' => [
                'order_uuid' => ['required', 'uuid'],
                'status_note' => ['nullable', 'string', 'max:255'],
            ],
        };
    }
```

In `roleAllows()`, the order types sit with sales — taking, packing, delivering and cancelling an order are all things whoever may record a sale may do:

```php
        return match ($type) {
            'customer', 'sale' => $policy->recordSale(),
            'order', 'order_pack', 'order_deliver', 'order_cancel' => $policy->recordSale(),
            'payment' => $policy->recordPayment(),
        };
```

Add the import: `use App\Services\OrderWriter;`

> Note: `rulesFor('order_pack')` returns rules that do **not** include `uuid`, but `push()` merges the envelope uuid into the payload before validating. Laravel ignores unvalidated keys, and `apply()` reads `order_uuid`, so this is correct — the envelope uuid stays the outbox's idempotency key while `order_uuid` names the target.

- [ ] **Step 5: Add orders to the delta pull**

In `pull()`, after the beats block, add — role-filtered the same way beats are:

```php
        // A salesman gets only the orders they took; owner/admin see all. Same
        // shape as beats. This assumes the order-taker delivers it.
        $orders = $isManager
            ? $delta(Order::class)
            : Order::where('sync_seq', '>', $since)
                ->where('created_by', (int) app('tenant.user_id'))
                ->orderBy('sync_seq')
                ->get();

        // Lines follow their order, so nothing on the device dangles.
        $orderLines = OrderLine::where('sync_seq', '>', $since)
            ->whereIn('order_id', $orders->pluck('id'))
            ->orderBy('sync_seq')
            ->get();
```

Add both to the `$maxSeqs` collection and to the JSON response as `'orders' => $orders, 'order_lines' => $orderLines`. Add imports for `App\Models\Order` and `App\Models\OrderLine`.

- [ ] **Step 6: Run and pass**

Run: `./vendor/bin/pest tests/Feature/Orders/OrderSyncTest.php tests/Feature/Sync/`
Expected: PASS (both files; the existing sync tests must be unaffected).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/SyncController.php tests/Feature/Orders/OrderSyncTest.php
git commit -m "feat: sync orders — four mutation types and role-filtered pull"
```

---

## Task 7: The owner's accept screen (TDD)

**Files:** Create `app/Http/Controllers/Web/OrderController.php`, `resources/views/orders/index.blade.php`, `tests/Feature/Web/OrdersTest.php`; modify `routes/web.php`, `lang/{en,hi}/orders.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Web/OrdersTest.php

use App\Models\Customer;
use App\Models\PackSize;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Orders\OrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A pending order for the owner to act on. Returns [owner, business, orderId, pack]. */
function pendingOrder(string $rate = '85.00'): array
{
    [$owner, $business] = pwOwner();

    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders', 'opening_balance' => '0.00',
    ]);
    $product = Product::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'name_hi' => 'सेव', 'name_en' => 'Sev',
    ]);
    $size = PackSize::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'label' => '500g', 'weight_kg' => '0.500',
    ]);
    $pack = ProductPack::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'product_id' => $product->id,
        'pack_size_id' => $size->id, 'default_sell_price' => '90.00',
        'default_cost_price' => '70.00',
    ]);

    $orderId = (string) Str::uuid();
    DB::connection('pgsql_migrate')->table('orders')->insert([
        'id' => $orderId, 'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'order_date' => '2026-07-26',
        'status' => OrderStatus::PENDING, 'total' => bcmul($rate, '2', 2),
        'created_by' => $owner->id, 'sync_seq' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('pgsql_migrate')->table('order_lines')->insert([
        'id' => (string) Str::uuid(), 'business_id' => $business->id, 'order_id' => $orderId,
        'product_pack_id' => $pack->id, 'qty' => 2, 'rate' => $rate,
        'line_total' => bcmul($rate, '2', 2), 'sync_seq' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$owner, $business, $orderId, $pack];
}

describe('access', function () {
    it('redirects a guest to login', function () {
        $this->get('/orders')->assertRedirect(route('login'));
    });

    it('sends a user who owns no business back to the app', function () {
        $this->actingAs(User::factory()->create())->get('/orders')->assertRedirect(route('app'));
    });
});

describe('accepting', function () {
    it('lists a pending order with its customer and total', function () {
        [$owner, $business] = pendingOrder();

        $this->actingAs($owner)->get('/orders?business=' . $business->id)
            ->assertOk()
            ->assertSee('Ram Traders')
            ->assertSee('₹170.00');
    });

    it('accepts an order as taken', function () {
        [$owner, $business, $orderId] = pendingOrder();

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
        ])->assertRedirect(route('orders', ['business' => $business->id]));

        $order = DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)->first();
        expect($order->status)->toBe(OrderStatus::ACCEPTED);
        expect($order->accepted_by)->toBe($owner->id);
        expect($order->accepted_at)->not->toBeNull();
    });

    it('accepts with adjusted quantities and prices, and retotals the order', function () {
        [$owner, $business, $orderId, $pack] = pendingOrder();
        $lineId = DB::connection('pgsql_migrate')->table('order_lines')
            ->where('order_id', $orderId)->value('id');

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
            'lines' => [$lineId => ['qty' => '3', 'rate' => '95.00']],
        ])->assertRedirect();

        $line = DB::connection('pgsql_migrate')->table('order_lines')->where('id', $lineId)->first();
        expect($line->qty)->toBe(3);
        expect((string) $line->rate)->toBe('95.00');
        expect((string) $line->line_total)->toBe('285.00');
        expect((string) DB::connection('pgsql_migrate')->table('orders')
            ->where('id', $orderId)->value('total'))->toBe('285.00');
    });

    it('refuses an adjusted price below the cost floor', function () {
        [$owner, $business, $orderId] = pendingOrder();
        $lineId = DB::connection('pgsql_migrate')->table('order_lines')
            ->where('order_id', $orderId)->value('id');

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
            'lines' => [$lineId => ['qty' => '2', 'rate' => '69.99']],
        ])->assertRedirect();

        // Still pending, still at the original rate: the edit was refused whole.
        $order = DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)->first();
        expect($order->status)->toBe(OrderStatus::PENDING);
        expect((string) DB::connection('pgsql_migrate')->table('order_lines')
            ->where('id', $lineId)->value('rate'))->toBe('85.00');
    });

    it('rejects an order with a reason', function () {
        [$owner, $business, $orderId] = pendingOrder();

        $this->actingAs($owner)->post('/orders/' . $orderId . '/reject', [
            'business' => $business->id, 'status_note' => 'No stock this week',
        ])->assertRedirect();

        $order = DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)->first();
        expect($order->status)->toBe(OrderStatus::REJECTED);
        expect($order->status_note)->toBe('No stock this week');
    });

    it('does not touch another tenant\'s order', function () {
        [$owner, $business] = pendingOrder();
        [, , $theirOrderId] = pendingOrder();

        $this->actingAs($owner)->post('/orders/' . $theirOrderId . '/accept', [
            'business' => $business->id,
        ])->assertNotFound();

        expect(DB::connection('pgsql_migrate')->table('orders')->where('id', $theirOrderId)->value('status'))
            ->toBe(OrderStatus::PENDING);
    });

    it('lets an admin accept, not only the owner', function () {
        [$owner, $business, $orderId] = pendingOrder();
        $admin = User::factory()->create();
        App\Models\Membership::on('pgsql_migrate')->create([
            'user_id' => $admin->id, 'business_id' => $business->id, 'role' => 'admin',
        ]);

        $this->actingAs($admin)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
        ])->assertRedirect();

        expect(DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)->value('status'))
            ->toBe(OrderStatus::ACCEPTED);
    });

    it('refuses to accept an order that is no longer pending', function () {
        [$owner, $business, $orderId] = pendingOrder();
        DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)
            ->update(['status' => OrderStatus::CANCELLED]);

        $this->actingAs($owner)->post('/orders/' . $orderId . '/accept', [
            'business' => $business->id,
        ])->assertRedirect();

        expect(DB::connection('pgsql_migrate')->table('orders')->where('id', $orderId)->value('status'))
            ->toBe(OrderStatus::CANCELLED);
    });
});
```

- [ ] **Step 2: Run and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Web/OrdersTest.php`
Expected: FAIL — route `/orders` does not exist.

- [ ] **Step 3: Let the owner-tool trait resolve an admin too**

`ResolvesOwnedTenant::ownedBusinessId()` looks up memberships `where('role', 'owner')`, so every existing owner tool is owner-only. The spec requires accept/reject for **owner and admin**, so the trait needs a role-parameterised lookup. Default it to `['owner']` so **no existing screen changes behaviour**.

In `app/Http/Controllers/Concerns/ResolvesOwnedTenant.php`, change the signature and the query:

```php
    /**
     * The id of a business this user owns — or, when $roles is widened, one
     * they hold any of those roles in.
     *
     * Defaults to owner only, which is what every existing owner tool means by
     * "owned". The order accept screen passes ['owner', 'admin'], because
     * approving an order is a manager's job rather than strictly the owner's.
     *
     * @param  list<string>  $roles
     */
    protected function ownedBusinessId(?string $requested, array $roles = ['owner']): ?string
    {
        return TenantContext::forUser((int) auth()->id(), function () use ($requested, $roles) {
            $query = Membership::where('user_id', auth()->id())->whereIn('role', $roles);

            if ($requested !== null) {
                $query->where('business_id', $requested);
            }

            return $query->value('business_id');
        });
    }
```

`runInTenant()` binds `tenant.role` to `'owner'`; that is now a lie for an admin, so make it bind the caller's real role in this business:

```php
    protected function runInTenant(string $businessId, callable $work): mixed
    {
        $role = TenantContext::forUser((int) auth()->id(), fn () => Membership::where('user_id', auth()->id())
            ->where('business_id', $businessId)->value('role')) ?? 'owner';

        return DB::transaction(function () use ($businessId, $work, $role) {
            TenantContext::switchTo($businessId);
            app()->bind('tenant.id', fn () => $businessId);
            app()->bind('tenant.user_id', fn () => (int) auth()->id());
            app()->bind('tenant.role', fn () => $role);

            return $work();
        });
    }
```

Run the existing owner-tool suites to prove nothing regressed:

Run: `./vendor/bin/pest tests/Feature/Web/`
Expected: PASS — every existing screen still resolves an owner exactly as before.

- [ ] **Step 4: Implement `app/Http/Controllers/Web/OrderController.php`**

```php
<?php
// app/Http/Controllers/Web/OrderController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductPack;
use App\Orders\OrderStatus;
use App\Pricing\PriceFloor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Accepting orders (PRD Phase: order workflow): Blade, online-only, owner/admin.
 *
 * Acceptance is deliberately the ONLY online step in the workflow — which is
 * what makes it the sync boundary: a salesman cannot pack until their phone has
 * pulled the decision.
 */
class OrderController extends Controller
{
    use ResolvesOwnedTenant;

    /** Accepting is a manager's job, so admins qualify as well as owners. */
    private const ROLES = ['owner', 'admin'];

    public function index(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'), self::ROLES);
        if ($businessId === null) {
            return redirect()->route('app');
        }

        [$pending, $recent] = $this->runInTenant($businessId, fn () => [
            Order::query()->where('status', OrderStatus::PENDING)
                ->with(['customer', 'lines.productPack.product', 'lines.productPack.packSize'])
                ->orderBy('order_date')->get(),
            Order::query()->whereNot('status', OrderStatus::PENDING)
                ->with('customer')->orderByDesc('updated_at')->limit(50)->get(),
        ]);

        return view('orders.index', [
            'businessId' => $businessId,
            'pending' => $pending,
            'recent' => $recent,
        ]);
    }

    public function accept(Request $request, string $order): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'), self::ROLES);
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.qty' => ['required_with:lines', 'integer', 'not_in:0'],
            'lines.*.rate' => ['required_with:lines', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $error = $this->runInTenant($businessId, function () use ($businessId, $order, $data) {
            $model = Order::query()->where('business_id', $businessId)->with('lines')->find($order);

            if ($model === null) {
                throw new NotFoundHttpException;
            }

            if (! OrderStatus::canTransition($model->status, OrderStatus::ACCEPTED)) {
                return __('orders.not_pending');
            }

            $total = '0.00';

            foreach ($model->lines as $line) {
                $edit = $data['lines'][$line->id] ?? null;
                $qty = $edit ? (int) $edit['qty'] : $line->qty;
                $rate = $edit ? bcadd((string) $edit['rate'], '0', 2) : (string) $line->rate;

                // The same floor the phone and LedgerWriter enforce. An edit
                // below cost must not sneak in through the accept screen.
                $pack = ProductPack::with(['product', 'packSize'])->find($line->product_pack_id);
                $floor = $pack ? PriceFloor::for($pack) : null;

                if ($floor !== null && bccomp($rate, $floor, 2) < 0) {
                    // Refuse the WHOLE acceptance: a half-applied edit would
                    // leave the shop promised one thing and billed another.
                    return __('sales.rate_below_floor', [
                        'product' => $pack->product?->name_en ?: $pack->product?->name_hi ?: 'this product',
                        'floor' => $floor,
                    ]);
                }

                $lineTotal = bcmul($rate, (string) $qty, 2);
                $total = bcadd($total, $lineTotal, 2);

                $line->qty = $qty;
                $line->rate = $rate;
                $line->line_total = $lineTotal;
                $line->save();
            }

            $model->status = OrderStatus::ACCEPTED;
            $model->accepted_by = (int) auth()->id();
            $model->accepted_at = Carbon::now();
            $model->total = $total;
            $model->save();

            return null;
        });

        return redirect()->route('orders', ['business' => $businessId])
            ->with($error === null ? 'status' : 'error', $error ?? __('orders.accepted'));
    }

    public function reject(Request $request, string $order): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'), self::ROLES);
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate(['status_note' => ['nullable', 'string', 'max:255']]);

        $this->runInTenant($businessId, function () use ($businessId, $order, $data) {
            $model = Order::query()->where('business_id', $businessId)->find($order);

            if ($model === null) {
                throw new NotFoundHttpException;
            }

            if (! OrderStatus::canTransition($model->status, OrderStatus::REJECTED)) {
                return;
            }

            $model->status = OrderStatus::REJECTED;
            $model->status_note = $data['status_note'] ?? null;
            $model->save();
        });

        return redirect()->route('orders', ['business' => $businessId])->with('status', __('orders.rejected'));
    }
}
```

> The accept edit runs inside `runInTenant`'s transaction, so a floor refusal on line 2 rolls back the change already made to line 1. That is why the whole acceptance is refused rather than the offending line skipped.

- [ ] **Step 5: Add the routes**

In `routes/web.php`, inside the same authenticated owner-tool group as `expenses`, and **before** any `orders/{order}` wildcard so literals win:

```php
    /*
     | Order acceptance — Blade, online-only, owner/admin. This is deliberately
     | the only online step in the order workflow: it is the sync boundary the
     | field depends on. {order} is resolved owner-scoped inside the controller.
     */
    Route::get('orders', [OrderController::class, 'index'])->name('orders');
    Route::post('orders/{order}/accept', [OrderController::class, 'accept'])->name('orders.accept');
    Route::post('orders/{order}/reject', [OrderController::class, 'reject'])->name('orders.reject');
```

Add `use App\Http\Controllers\Web\OrderController;` to the imports.

- [ ] **Step 6: Add the remaining language keys**

Append to `lang/en/orders.php`:

```php
    'title' => 'Orders',
    'heading' => 'Orders to accept',
    'pending_none' => 'No orders waiting.',
    'customer' => 'Customer',
    'order_date' => 'Ordered',
    'product' => 'Product',
    'qty' => 'Qty',
    'rate' => 'Rate',
    'total' => 'Total',
    'accept' => 'Accept',
    'reject' => 'Reject',
    'reason' => 'Reason',
    'accepted' => 'Order accepted.',
    'rejected' => 'Order rejected.',
    'not_pending' => 'That order is no longer waiting to be accepted.',
    'recent' => 'Recent orders',
    'status' => 'Status',
    'statuses' => [
        'pending' => 'Waiting', 'accepted' => 'Accepted', 'packed' => 'Packed',
        'delivered' => 'Delivered', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
    ],
```

Append to `lang/hi/orders.php`:

```php
    'title' => 'ऑर्डर',
    'heading' => 'स्वीकार करने योग्य ऑर्डर',
    'pending_none' => 'कोई ऑर्डर प्रतीक्षा में नहीं।',
    'customer' => 'ग्राहक',
    'order_date' => 'ऑर्डर दिनांक',
    'product' => 'उत्पाद',
    'qty' => 'मात्रा',
    'rate' => 'दर',
    'total' => 'कुल',
    'accept' => 'स्वीकारें',
    'reject' => 'अस्वीकारें',
    'reason' => 'कारण',
    'accepted' => 'ऑर्डर स्वीकार किया गया।',
    'rejected' => 'ऑर्डर अस्वीकार किया गया।',
    'not_pending' => 'यह ऑर्डर अब स्वीकृति की प्रतीक्षा में नहीं है।',
    'recent' => 'हाल के ऑर्डर',
    'status' => 'स्थिति',
    'statuses' => [
        'pending' => 'प्रतीक्षारत', 'accepted' => 'स्वीकृत', 'packed' => 'पैक हुआ',
        'delivered' => 'पहुँचा दिया', 'rejected' => 'अस्वीकृत', 'cancelled' => 'रद्द',
    ],
```

- [ ] **Step 7: Create `resources/views/orders/index.blade.php`**

```blade
{{-- resources/views/orders/index.blade.php --}}
@extends('layouts.app')

@section('title', __('orders.title') . ' — ' . config('app.name'))

@section('content')
@php use App\Support\Inr; @endphp
<div class="mx-auto max-w-5xl p-4">
    <header class="mb-4 flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('orders.heading') }}</h1>
        <a href="{{ route('reports.dashboard', ['business' => $businessId]) }}"
           class="text-sm text-brand">{{ __('reminders.back_to_dashboard') }}</a>
    </header>

    @if (session('status'))
        <p class="card mb-3 text-sm">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="card mb-3 text-sm text-danger">{{ session('error') }}</p>
    @endif

    @if ($pending->isEmpty())
        <p class="card text-sm text-ink-muted">{{ __('orders.pending_none') }}</p>
    @endif

    @foreach ($pending as $order)
        <div class="card mt-4">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="font-semibold">{{ $order->customer?->name ?? '—' }}</h2>
                <span class="text-xs text-ink-muted">
                    {{ __('orders.order_date') }}: {{ $order->order_date?->format('d M Y') }}
                </span>
            </div>

            <form method="POST" action="{{ route('orders.accept', ['order' => $order->id]) }}">
                @csrf
                <input type="hidden" name="business" value="{{ $businessId }}">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-ink-muted">
                            <th>{{ __('orders.product') }}</th>
                            <th class="text-right">{{ __('orders.qty') }}</th>
                            <th class="text-right">{{ __('orders.rate') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->lines as $line)
                            <tr>
                                <td>
                                    {{ $line->productPack?->product?->name_en ?: $line->productPack?->product?->name_hi }}
                                    {{ $line->productPack?->packSize?->label }}
                                </td>
                                <td class="text-right">
                                    <input type="number" class="field-input w-20 text-right"
                                           name="lines[{{ $line->id }}][qty]" value="{{ $line->qty }}">
                                </td>
                                <td class="text-right">
                                    <input type="number" step="0.01" min="0" class="field-input w-24 text-right"
                                           name="lines[{{ $line->id }}][rate]" value="{{ $line->rate }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p class="mt-2 text-right font-bold">{{ __('orders.total') }}: {{ Inr::format($order->total) }}</p>
                <button type="submit" class="btn-primary mt-2">{{ __('orders.accept') }}</button>
            </form>

            <form method="POST" action="{{ route('orders.reject', ['order' => $order->id]) }}"
                  class="mt-2 flex items-end gap-2">
                @csrf
                <input type="hidden" name="business" value="{{ $businessId }}">
                <label class="text-sm">
                    <span class="block text-ink-muted">{{ __('orders.reason') }}</span>
                    <input type="text" name="status_note" maxlength="255" class="field-input">
                </label>
                <button type="submit" class="text-sm text-danger">{{ __('orders.reject') }}</button>
            </form>
        </div>
    @endforeach

    <div class="card mt-6 overflow-x-auto">
        <h2 class="mb-2 font-semibold">{{ __('orders.recent') }}</h2>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-ink-muted">
                    <th>{{ __('orders.customer') }}</th>
                    <th>{{ __('orders.status') }}</th>
                    <th class="text-right">{{ __('orders.total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recent as $order)
                    <tr>
                        <td>{{ $order->customer?->name ?? '—' }}</td>
                        <td>{{ __('orders.statuses.' . $order->status) }}</td>
                        <td class="tabular text-right">{{ Inr::format($order->total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
```

- [ ] **Step 8: Run and pass**

Run: `php artisan view:clear && ./vendor/bin/pest tests/Feature/Web/OrdersTest.php`
Expected: PASS (10 passing).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Web/OrderController.php resources/views/orders routes/web.php \
        lang/en/orders.php lang/hi/orders.php tests/Feature/Web/OrdersTest.php
git commit -m "feat: owner accept/reject screen for orders"
```

---

## Task 8: The phone — Dexie, the orders module, and the two screens

**Files:** Create `resources/js/offline/orders.js`, `resources/js/offline/orders.test.js`, `resources/js/screens/Orders.jsx`; modify `db.js`, `sync.js`, `outbox.js`, `Forms.jsx`, `main.jsx`, `i18n.js`

- [ ] **Step 1: Write the failing test for the pure module**

```js
// resources/js/offline/orders.test.js
import { describe, expect, it } from 'vitest';
import { actionsFor, groupByStatus } from './orders';

describe('actionsFor', () => {
    it('offers nothing while the owner has not decided', () => {
        // The sync boundary made visible: until the acceptance arrives, the
        // salesman genuinely does not know whether to pack.
        expect(actionsFor('pending')).toEqual(['cancel']);
    });

    it('offers packing once accepted', () => {
        expect(actionsFor('accepted')).toEqual(['pack', 'cancel']);
    });

    it('offers delivery once packed', () => {
        expect(actionsFor('packed')).toEqual(['deliver', 'cancel']);
    });

    it('offers nothing on a finished order', () => {
        expect(actionsFor('delivered')).toEqual([]);
        expect(actionsFor('rejected')).toEqual([]);
        expect(actionsFor('cancelled')).toEqual([]);
    });

    it('offers nothing for a status it does not know', () => {
        expect(actionsFor('banana')).toEqual([]);
    });
});

describe('groupByStatus', () => {
    it('groups orders under their status, newest first within each', () => {
        const grouped = groupByStatus([
            { id: 'a', status: 'pending', order_date: '2026-07-01' },
            { id: 'b', status: 'packed', order_date: '2026-07-03' },
            { id: 'c', status: 'pending', order_date: '2026-07-05' },
        ]);

        expect(grouped.pending.map((o) => o.id)).toEqual(['c', 'a']);
        expect(grouped.packed.map((o) => o.id)).toEqual(['b']);
        expect(grouped.delivered).toEqual([]);
    });
});
```

- [ ] **Step 2: Run and watch it fail**

Run: `npx vitest run resources/js/offline/orders.test.js`
Expected: FAIL — cannot resolve `./orders`.

- [ ] **Step 3: Implement `resources/js/offline/orders.js`**

```js
/**
 * What a salesman may do with an order, and how the list is grouped.
 *
 * Pure so it can be tested — the repo has no component-test tooling, so any
 * rule that lives only inside a component cannot be covered at all.
 *
 * These mirror App\Orders\OrderStatus on the server, which is the authority.
 * The client's copy exists so the UI can hide an action the server would
 * refuse, not to decide anything on its own.
 */

const ACTIONS = {
    pending: ['cancel'],
    accepted: ['pack', 'cancel'],
    packed: ['deliver', 'cancel'],
    delivered: [],
    rejected: [],
    cancelled: [],
};

export const ORDER_STATUSES = ['pending', 'accepted', 'packed', 'delivered', 'rejected', 'cancelled'];

/** @returns {string[]} the actions this status permits, newest-first ordering. */
export function actionsFor(status) {
    return ACTIONS[status] ?? [];
}

/** @returns {Record<string, object[]>} every status key present, newest first. */
export function groupByStatus(orders) {
    const grouped = Object.fromEntries(ORDER_STATUSES.map((s) => [s, []]));

    for (const order of orders ?? []) {
        if (grouped[order.status]) grouped[order.status].push(order);
    }

    for (const status of ORDER_STATUSES) {
        grouped[status].sort((a, b) => String(b.order_date).localeCompare(String(a.order_date)));
    }

    return grouped;
}
```

- [ ] **Step 4: Run and pass**

Run: `npx vitest run resources/js/offline/orders.test.js`
Expected: PASS (7 passing).

- [ ] **Step 5: Add the Dexie stores**

In `resources/js/offline/db.js`, after the `db.version(5)` block:

```js
    /**
     * Orders (order workflow). Keyed on `uuid` like sales: an order is created
     * on the phone, so it has a client idempotency key before it has a server
     * id — and the sale created on delivery reuses that same uuid.
     */
    db.version(6).stores({
        orders: 'uuid, id, customer_id, status, order_date, sync_seq',
        order_lines: 'id, order_id, sync_seq',
    });
```

- [ ] **Step 6: Add both to the pull and the push types**

In `resources/js/offline/sync.js`, add to `PULL_TABLES`:

```js
    'orders',
    'order_lines',
```

In `resources/js/offline/outbox.js`, extend `PUSHABLE_TYPES`:

```js
export const PUSHABLE_TYPES = [
    'customer', 'sale', 'payment',
    'order', 'order_pack', 'order_deliver', 'order_cancel',
];
```

- [ ] **Step 7: Turn `RecordSale` into `RecordOrder`**

In `resources/js/screens/Forms.jsx`, rename the export and its title, leaving the line editor, rate field, floor block and subtotal exactly as they are:

```js
export function RecordOrder({ customer, packs, products = [], onSave }) {
```

and the screen title:

```jsx
        <Screen title={t('take_order')} onBack={() => navigate(`/khata/${customer.uuid}`)}>
```

Add to `resources/js/i18n.js`, both maps:

```js
        take_order: 'Take order',
        orders: 'Orders',
        pack: 'Mark packed',
        deliver: 'Mark delivered',
        cancel_order: 'Cancel',
        awaiting_acceptance: 'Waiting for the owner to accept',
```
```js
        take_order: 'ऑर्डर लें',
        orders: 'ऑर्डर',
        pack: 'पैक हुआ',
        deliver: 'पहुँचा दिया',
        cancel_order: 'रद्द करें',
        awaiting_acceptance: 'मालिक की स्वीकृति की प्रतीक्षा',
```

- [ ] **Step 8: Create `resources/js/screens/Orders.jsx`**

```jsx
import { t } from '../i18n';
import { actionsFor, groupByStatus, ORDER_STATUSES } from '../offline/orders';
import { formatRupees, toPaise } from '../offline/money';
import { navigate } from '../router';
import { Screen } from '../components/Chrome';

/**
 * The salesman's orders, grouped by status, offering only the actions each
 * state allows. A pending order shows why it has no actions rather than a
 * mysterious disabled button — the acceptance simply has not synced yet.
 */
export function Orders({ orders, customersById, onAction }) {
    const grouped = groupByStatus(orders);

    return (
        <Screen title={t('orders')} onBack={() => navigate('/khata')}>
            {ORDER_STATUSES.map((status) => (
                grouped[status].length > 0 && (
                    <section key={status} className="mb-4">
                        <h2 className="mb-2 text-sm font-semibold text-ink-muted">{t(status)}</h2>
                        <ul className="space-y-2">
                            {grouped[status].map((order) => (
                                <li key={order.uuid} className="card py-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-medium">
                                                {customersById.get(order.customer_id)?.name ?? '—'}
                                            </span>
                                            <span className="block text-sm text-ink-muted">{order.order_date}</span>
                                        </span>
                                        <span className="tabular shrink-0 font-medium">
                                            {formatRupees(toPaise(order.total ?? '0'))}
                                        </span>
                                    </div>

                                    {status === 'pending' && (
                                        <p className="mt-1 text-xs text-ink-muted">{t('awaiting_acceptance')}</p>
                                    )}

                                    <div className="mt-2 flex gap-2">
                                        {actionsFor(status).map((action) => (
                                            <button
                                                key={action}
                                                type="button"
                                                className={action === 'cancel' ? 'text-xs text-danger' : 'btn-secondary'}
                                                onClick={() => onAction(order, action)}
                                                data-testid={`order-${action}-${order.uuid}`}
                                            >
                                                {t(action === 'cancel' ? 'cancel_order' : action)}
                                            </button>
                                        ))}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )
            ))}
        </Screen>
    );
}
```

- [ ] **Step 9: Wire it up in `main.jsx`**

Change the route table entries and add the orders route:

```js
    '/order/:uuid': 'order',
    '/order': 'pick-customer',
    '/orders': 'orders',
```

(Keep `/sale/:uuid` and `/sale` mapped to the same names for a beat, so an old bookmark still works.)

Change the import and render, and add the state and handlers:

```js
import { NewCustomer, RecordPayment, RecordOrder } from './screens/Forms';
import { Orders } from './screens/Orders';
```

```js
    const [orders, setOrders] = useState([]);
```

In `refresh`, alongside the other reads:

```js
            setOrders(await database.orders.toArray());
```

The save handler, replacing `saveSale`:

```js
    const saveOrder = async ({ customer, sale_date, lines, total }) => {
        if (blockedByImpersonation()) return;

        await enqueue(db, {
            type: 'order',
            tenantId,
            uuid: crypto.randomUUID(),
            payload: { customer_id: customer.id ?? customer.uuid, order_date: sale_date, lines, total },
            dependsOnUuid: customer.id ? null : customer.uuid,
        });

        await refresh(db);
        if (online) runSync();
    };

    /** pack | deliver | cancel — each is its own outbox mutation. */
    const orderAction = async (order, action) => {
        if (blockedByImpersonation()) return;

        await enqueue(db, {
            type: `order_${action}`,
            tenantId,
            uuid: crypto.randomUUID(),
            payload: { order_uuid: order.uuid },
        });

        await refresh(db);
        if (online) runSync();
    };
```

And the two render cases:

```jsx
            case 'order':
                return <RecordOrder customer={activeCustomer} packs={packs} products={products} onSave={saveOrder} />;
            case 'orders':
                return <Orders orders={orders} customersById={new Map(customers.map((c) => [c.id, c]))} onAction={orderAction} />;
```

- [ ] **Step 10: Verify**

Run: `npx vitest run` — all green.
Run: `npx vite build` — clean. `public/build` is gitignored, so nothing to clean up.

- [ ] **Step 11: Commit**

```bash
git add resources/js
git commit -m "feat: take orders and drive pack/deliver from the phone"
```

---

## Task 9: Full suite, docs, wrap-up

- [ ] **Step 1: Run both suites**

```bash
php artisan test      # expect 715 baseline + the new PHP cases, 0 failures
npx vitest run        # expect 149 baseline + the new JS cases, 0 failures
```

- [ ] **Step 2: Update `docs/ui-backlog.md`**

Add an `F-13` row above `F-12` recording: orders now precede sales in the app; delivery creates the sale reusing the order's uuid so a replayed delivery cannot double a khata; the sale is dated the delivery day and credited to whoever delivered; status is forward-only one step at a time and never leaves a terminal state; acceptance is the only online step and is therefore the sync boundary; a rejected order beats a late delivery from the field; packing does not touch stock; and the two known limitations (acceptance edits are not recorded, and sync visibility assumes the order-taker delivers). Reference the spec and plan paths.

- [ ] **Step 3: Manual check (recommended)**

`php artisan serve`, then as `salesman@demo-namkeen-bhandar.test` / `password123` take an order; as `owner@demo-namkeen-bhandar.test` accept it at `/orders` with an adjusted quantity; back as the salesman, sync, mark packed then delivered; confirm the customer's khata rises only at the last step and the sale appears in their ledger with the accepted rates.

- [ ] **Step 4: Commit and finish the branch**

```bash
git add docs/ui-backlog.md
git commit -m "docs: log the order workflow in ui-backlog (F-13)"
```

Open a PR and squash-merge (`gh api` REST — `gh pr` subcommands fail on this repo).

---

## Self-review notes (traceability to the spec)

- Decision 1 (delivery creates the sale, separate tables) → Task 2 schema, Task 5 `deliver`, plus the explicit "khata does not move" tests in Tasks 3 and 5.
- Decision 2 (orders replace direct sales in the app only) → Task 8 renames the screen; `sale` stays in `apply()`/`rulesFor()`/`PUSHABLE_TYPES` untouched in Task 6.
- Decision 3 (full delivery only) → `deliver()` takes no quantities; one sale per order.
- Decision 4 (forward-only) → Task 1, tightened to exactly one step, with tests for skipping, rewinding and terminal states.
- Decision 5 (acceptance is the sync boundary) → no accept mutation type in Task 6; Task 4's "refuses to pack an order the owner has not accepted yet".
- Decision 6 (rejection beats late delivery) → Task 5's rejected-order test and Task 6's park-without-killing-the-batch test.
- Decision 7 (edits at acceptance, not recorded) → Task 7 accept-with-edits; nothing stores the original, as agreed.
- Decision 8 (packing does not touch stock) → no stock code in any task.
- `sale_date` = delivery date, `created_by` = deliverer → asserted in Task 5 Step 1.
- Sale reuses the order's uuid → asserted in Task 5 Step 1 and proven by the replay test.
- DPDP registration → Task 2 Step 5, proven by the existing "covers every tenant-owned table" test.
- Permissions ("accept/reject are owner/admin") → Task 7 Step 3 widens
  `ResolvesOwnedTenant` to take roles, defaulting to `['owner']` so no existing
  screen changes, with a test that an admin can accept.
