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

        $this->actingAs($owner)
            ->get('/reports/dashboard')
            ->assertOk()
            ->assertSee(__('reports.heading'))
            ->assertSee(__('reports.customer_outstanding'))
            ->assertSee('₹1,500.00')    // Inr-formatted total outstanding
            ->assertSee('Ramesh')       // per-customer summary list renders the name
            ->assertSee('Rampur');      // ...and the village
    });

    it('clamps an out-of-range month without erroring', function () {
        [$owner] = reportsOwner();

        $this->actingAs($owner)
            ->get('/reports/dashboard?year=2026&month=99')
            ->assertOk();
    });
});
