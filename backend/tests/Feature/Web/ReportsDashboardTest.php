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
            'name' => 'Ramesh', 'village' => 'Rampur', 'opening_balance' => '1500.00',
        ]);
        // An expense for the current month so the P&L Net Profit line renders.
        $e = new App\Models\Expense([
            'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
            'category' => 'rent', 'amount' => '1200.00', 'spent_on' => now()->format('Y-m-d'),
        ]);
        $e->setConnection('pgsql_migrate');
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
            ->assertSee(__('reports.est_gross_profit'))  // gross-profit row in the P&L block
            ->assertSee(__('reports.gross_profit_caveat'))
            ->assertSee(__('reports.net_profit'))        // P&L block renders
            ->assertSee(__('reports.expenses'))          // expenses line in P&L
            ->assertSee(__('reports.monthly_money_chart')) // grouped ₹ chart title
            ->assertSee('₹0');                            // a y-axis tick label renders
    });

    it('clamps an out-of-range month without erroring', function () {
        [$owner] = reportsOwner();

        $this->actingAs($owner)
            ->get('/reports/dashboard?year=2026&month=99')
            ->assertOk();
    });
});
