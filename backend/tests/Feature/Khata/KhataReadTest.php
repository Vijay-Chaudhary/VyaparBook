<?php
// tests/Feature/Khata/KhataReadTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

function khataReadToken(Business $business, User $user, string $role = 'owner'): string
{
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);

    return (new TokenService())->issue($user, $membership);
}

function readCustomer(Business $business, string $name, string $opening = '0.00'): Customer
{
    return Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'opening_balance' => $opening,
    ]);
}

function seedSale(Customer $c, User $u, string $total, string $date, ?string $reverses = null): Sale
{
    $sale = new Sale([
        'business_id' => $c->business_id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'sale_date' => $date, 'reverses_id' => $reverses,
    ]);
    $sale->setConnection('pgsql_migrate');
    $sale->created_by = $u->id;
    $sale->total = $total;
    $sale->save();

    return $sale;
}

function seedPayment(Customer $c, User $u, string $amount, string $date): Payment
{
    $payment = new Payment([
        'business_id' => $c->business_id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $c->id, 'payment_date' => $date, 'amount' => $amount, 'mode' => 'cash',
    ]);
    $payment->setConnection('pgsql_migrate');
    $payment->created_by = $u->id;
    $payment->save();

    return $payment;
}

it('lists customers with their outstanding, excluding archived by default', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $token = khataReadToken($business, $user);

    $ram = readCustomer($business, 'Ram Traders', '100.00');
    seedSale($ram, $user, '270.00', '2026-07-10');
    seedPayment($ram, $user, '200.00', '2026-07-12'); // outstanding = 170.00

    $shyam = readCustomer($business, 'Shyam Stores', '50.00');
    $shyam->archived_at = now();
    $shyam->save();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/khata')
        ->assertOk()
        ->assertJsonCount(1, 'customers');

    expect($response->json('customers.0.name'))->toBe('Ram Traders');
    expect($response->json('customers.0.outstanding'))->toBe('170.00');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/khata?include_archived=1')
        ->assertJsonCount(2, 'customers');
});

it('returns a time-ordered ledger whose running balance ends at outstanding', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $token = khataReadToken($business, $user);

    $ram = readCustomer($business, 'Ram Traders', '100.00');
    seedSale($ram, $user, '270.00', '2026-07-10');
    seedPayment($ram, $user, '200.00', '2026-07-12');
    seedSale($ram, $user, '58.50', '2026-07-15');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/khata/{$ram->id}")
        ->assertOk()
        ->assertJson(['outstanding' => '228.50']);

    expect($response->json('ledger'))->toHaveCount(3);
    expect(collect($response->json('ledger'))->pluck('running_balance')->all())
        ->toBe(['370.00', '170.00', '228.50']);
});

it('shows a reversal as its own ledger entry that nets out', function () {
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $token = khataReadToken($business, $user);

    $ram = readCustomer($business, 'Ram Traders', '0.00');
    $sale = seedSale($ram, $user, '180.00', '2026-07-10');
    seedSale($ram, $user, '-180.00', '2026-07-11', $sale->id);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/khata/{$ram->id}")
        ->assertOk()
        ->assertJson(['outstanding' => '0.00']);

    expect(collect($response->json('ledger'))->pluck('kind')->all())
        ->toBe(['sale', 'sale_reversal']);
});

it('lets a salesman and an accountant read the khata', function () {
    $business = Business::factory()->create();

    foreach (['salesman', 'accountant'] as $role) {
        $token = khataReadToken($business, User::factory()->create(), $role);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/khata')
            ->assertOk();
    }
});

it('returns 404 for a customer in another business', function () {
    $mine = Business::factory()->create();
    $theirs = Business::factory()->create();
    $token = khataReadToken($mine, User::factory()->create());

    $foreign = readCustomer($theirs, 'Theirs');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/khata/{$foreign->id}")
        ->assertStatus(404);
});
