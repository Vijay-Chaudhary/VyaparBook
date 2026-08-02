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
    Membership::create([
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
    it('links each customer in the outstanding list to their khata', function () {
        [$owner, $business] = reportsOwner();
        $customer = Customer::create([
            'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
            'name' => 'Ramesh', 'village' => 'Rampur', 'opening_balance' => '1500.00',
        ]);

        $this->actingAs($owner)
            ->get('/reports/dashboard')
            ->assertOk()
            ->assertSee(route('customers.show', [
                'customer' => $customer->id, 'business' => $business->id,
            ]), false);
    });

    it('shows the dashboard heading and the total-due figure for the owner', function () {
        [$owner, $business] = reportsOwner();
        Customer::create([
            'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
            'name' => 'Ramesh', 'village' => 'Rampur', 'opening_balance' => '1500.00',
        ]);
        // An expense for the current month so the P&L Net Profit line renders.
        $e = new App\Models\Expense([
            'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
            'category' => 'rent', 'amount' => '1200.00', 'spent_on' => now()->format('Y-m-d'),
        ]);
        $e->created_by = $owner->id;
        $e->save();

        $this->actingAs($owner)
            ->get('/reports/dashboard')
            ->assertOk()
            ->assertSee(__('reports.heading'))
            ->assertSee(__('reports.customer_outstanding'))
            ->assertSee('₹1,500.00')    // Inr-formatted total outstanding
            ->assertSee('Ramesh')       // per-customer summary list renders the name
            ->assertSee('Rampur')       // ...and the village
            ->assertSee(__('reports.gross_profit'))  // gross-profit row in the P&L block
            ->assertSee(__('reports.gross_profit_caveat'))
            ->assertSee(__('reports.net_profit'))        // P&L block renders
            ->assertSee(__('reports.expenses'))          // expenses line in P&L
            ->assertSee(__('reports.monthly_money_chart')) // grouped ₹ chart title
            ->assertSee('₹0')                             // a y-axis tick label renders
            ->assertSee('Net profit: −₹1,200.00')         // hover tooltip carries the full value
            ->assertSee(__('reports.cash_flow'))          // cash-flow section renders
            ->assertSee(__('reports.cash_position'))      // cash position tile + column
            ->assertSee(__('reports.net_cash'))           // net-cash column + chart
            ->assertSee(__('reports.cash_position_hint')); // "not a bank balance" caption
    });

    it('clamps an out-of-range month without erroring', function () {
        [$owner] = reportsOwner();

        $this->actingAs($owner)
            ->get('/reports/dashboard?year=2026&month=99')
            ->assertOk();
    });
});

describe('phase 2a', function () {
    it('shows stock value, the valuation table and supplier payables', function () {
        [$owner, $business] = pwOwner();
        $sup = pwSupplier($business, 'Besan Traders', '500.00');
        pwMaterial($business, 'Besan');
        pwMaterial($business, 'Salt');           // never purchased → shown as —

        // 100kg @ ₹42 = ₹4,200 stock value; supplier payable 500 + 4,200 = 4,700.
        $this->actingAs($owner)->post('/purchases', [
            'business' => $business->id, 'supplier_id' => $sup->id,
            'raw_material_id' => asTenant($business->id,
                fn () => \App\Models\RawMaterial::where('name', 'Besan')->value('id')),
            'purchase_date' => '2026-07-04', 'qty' => '100', 'unit_cost' => '42',
        ])->assertRedirect();

        $this->actingAs($owner)->get('/reports/dashboard?business=' . $business->id)
            ->assertOk()
            ->assertSee('Stock value')
            ->assertSee('₹4,200.00')      // tile + valuation total + Besan's row
            ->assertSee('₹42.00')         // weighted-average cost per kg
            ->assertSee('Besan Traders')  // supplier payables section
            ->assertSee('₹4,700.00')      // opening 500 + the purchase
            ->assertSee('Salt');          // unpriced material still listed
    });

    it('does not leak another tenant\'s stock or payables into the dashboard', function () {
        [$owner, $business] = pwOwner();
        [$otherOwner, $other] = pwOwner();

        $foreignSup = pwSupplier($other, 'Other Shop Supplier', '9999.00');
        pwMaterial($other, 'Foreign Material');
        $this->actingAs($otherOwner)->post('/purchases', [
            'business' => $other->id, 'supplier_id' => $foreignSup->id,
            'raw_material_id' => asTenant($other->id,
                fn () => \App\Models\RawMaterial::value('id')),
            'purchase_date' => '2026-07-04', 'qty' => '10', 'unit_cost' => '900',
        ]);

        $this->actingAs($owner)->get('/reports/dashboard?business=' . $business->id)
            ->assertOk()
            ->assertDontSee('Other Shop Supplier')
            ->assertDontSee('Foreign Material')
            ->assertDontSee('₹9,000.00');   // the other tenant's stock value
    });
});

describe('phase 2b', function () {
    it('shows gross profit costed from production, and flags the estimated share', function () {
        [$owner, $business] = pwOwner();
        $u = App\Models\User::factory()->create();

        $besan = pwMaterial($business, 'Besan');
        cogsBuy($business, $u, $besan, '100', '40');          // ₹40/kg

        // Produced: actual ₹40/pack, though the owner typed ₹93.
        [$sev, $sevPack] = cogsProduct($business, 'Sev', '1.000', '93.00');
        cogsBatch($business, $u, $sev, '10.000', [$besan->id => '10.000']);

        // Bought in: never produced, so ₹77 estimate stands and is flagged.
        [, $namkeenPack] = cogsProduct($business, 'Namkeen', '1.000', '77.00');

        $c = App\Models\Customer::create([
            'business_id' => $business->id, 'uuid' => (string) Illuminate\Support\Str::uuid(),
            'name' => 'Ramesh', 'opening_balance' => '0.00',
        ]);
        $sale = dashSale($c, $u, '2000.00', now()->format('Y-m-d'));
        saleLine($sale, $sevPack, 10, '100.00');
        saleLine($sale, $namkeenPack, 10, '100.00');

        $this->actingAs($owner)
            ->get('/reports/dashboard?business=' . $business->id
                . '&year=' . now()->year . '&month=' . now()->month)
            ->assertOk()
            ->assertSee('Gross profit')
            ->assertSee('₹830.00')          // 2000 − (10×40 + 10×77), not 300
            ->assertSee('₹1,000.00');       // the still-estimated half, flagged
    });
});

describe('finished goods', function () {
    it('shows produced, sold and on-hand kg per product', function () {
        [$owner, $business] = reportsOwner();

        $product = App\Models\Product::create([
            'business_id' => $business->id, 'name_hi' => 'Aloo Bhujia', 'name_en' => 'Aloo Bhujia',
        ]);

        $batch = new App\Models\ProductionBatch([
            'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
            'product_id' => $product->id, 'batch_date' => now()->toDateString(), 'output_kg' => '20.000',
        ]);
        $batch->created_by = $owner->id;
        $batch->save();

        $this->actingAs($owner)->get('/reports/dashboard?business=' . $business->id)
            ->assertOk()
            ->assertSee(__('reports.finished_goods'))
            ->assertSee(__('reports.on_hand_kg'))
            ->assertSee('Aloo Bhujia')
            ->assertSee('20');
    });
});

it('links to every owner tool, so none of them is reachable by URL only', function () {
    // Four screens shipped with no link at all (orders, beats, gst, and
    // invoices via gst). A feature nobody can navigate to is not shipped.
    [$owner, $business] = reportsOwner();

    $response = $this->actingAs($owner)->get('/reports/dashboard?business=' . $business->id)->assertOk();

    foreach (['orders', 'expenses', 'purchases', 'suppliers', 'customers', 'beats', 'gst'] as $tool) {
        expect($response->getContent())->toContain(route($tool, ['business' => $business->id]));
    }
});
