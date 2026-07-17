<?php
// tests/Feature/Khata/PaymentTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\User;
use App\Services\KhataService;
use App\Services\TokenService;
use Illuminate\Support\Str;

/** [business, token, customer] with a member of the given role and one customer. */
function paymentSetup(string $role = 'owner', string $opening = '500.00'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::on('pgsql_migrate')->create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);
    $token = (new TokenService())->issue($user, $membership);

    $customer = Customer::on('pgsql_migrate')->create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders', 'opening_balance' => $opening,
    ]);

    return [$business, $token, $customer];
}

function postPayment(string $token, Customer $customer, string $amount, ?string $uuid = null)
{
    return test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/payments', [
            'uuid' => $uuid ?? (string) Str::uuid(),
            'customer_id' => $customer->id,
            'payment_date' => '2026-07-17',
            'amount' => $amount,
            'mode' => 'cash',
        ]);
}

it('records a payment that lowers outstanding', function () {
    [$business, $token, $customer] = paymentSetup(opening: '500.00');

    postPayment($token, $customer, '200.00')
        ->assertStatus(201)
        ->assertJson(['amount' => '200.00', 'mode' => 'cash']);

    $fresh = Customer::on('pgsql_migrate')->find($customer->id);
    expect((new KhataService())->outstandingFor($fresh))->toBe('300.00'); // 500 - 200
});

it('replays the same payment when the same uuid is posted twice', function () {
    [$business, $token, $customer] = paymentSetup();
    $uuid = (string) Str::uuid();

    $first = postPayment($token, $customer, '200.00', $uuid)->assertStatus(201);
    $second = postPayment($token, $customer, '200.00', $uuid)->assertStatus(200);

    expect($second->json('id'))->toBe($first->json('id'));
    expect(Payment::on('pgsql_migrate')->where('business_id', $business->id)->count())->toBe(1);
});

it('lets an accountant record a payment but not reverse one', function () {
    [$business, $token, $customer] = paymentSetup('accountant');

    $payment = postPayment($token, $customer, '200.00')->assertStatus(201);

    test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/payments/{$payment->json('id')}/reverse")
        ->assertStatus(403);
});

it('reverses a payment append-only and restores outstanding', function () {
    [$business, $token, $customer] = paymentSetup(opening: '500.00');

    $payment = postPayment($token, $customer, '200.00')->assertStatus(201);

    test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/payments/{$payment->json('id')}/reverse")
        ->assertStatus(201)
        ->assertJson(['amount' => '-200.00', 'reverses_id' => $payment->json('id')]);

    // Original untouched; outstanding back to the opening balance.
    $original = Payment::on('pgsql_migrate')->find($payment->json('id'));
    expect($original->reverses_id)->toBeNull();
    $fresh = Customer::on('pgsql_migrate')->find($customer->id);
    expect((new KhataService())->outstandingFor($fresh))->toBe('500.00');
});

it('409s on a double reverse', function () {
    [$business, $token, $customer] = paymentSetup();

    $payment = postPayment($token, $customer, '200.00')->assertStatus(201);
    $url = "/api/v1/payments/{$payment->json('id')}/reverse";

    test()->withHeader('Authorization', "Bearer {$token}")->postJson($url)->assertStatus(201);
    test()->withHeader('Authorization', "Bearer {$token}")->postJson($url)->assertStatus(409);
});

it('rejects an invalid payment mode and a non-positive amount', function () {
    [$business, $token, $customer] = paymentSetup();

    test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/payments', [
            'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'payment_date' => '2026-07-17', 'amount' => '200.00', 'mode' => 'bitcoin',
        ])->assertStatus(422);

    postPayment($token, $customer, '0')->assertStatus(422);
});

it('returns 404 recording a payment for another businesses customer', function () {
    [$mine, $token, $mineCustomer] = paymentSetup();
    $theirs = Business::factory()->create();
    $foreign = Customer::on('pgsql_migrate')->create([
        'business_id' => $theirs->id, 'uuid' => (string) Str::uuid(), 'name' => 'Theirs',
    ]);

    postPayment($token, $foreign, '200.00')->assertStatus(404);
});
