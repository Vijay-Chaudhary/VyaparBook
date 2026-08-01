<?php
// tests/Unit/PaymentModelTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Str;

function paymentCustomerFor(Business $business): Customer
{
    return Customer::create([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'name' => 'Ram Traders',
    ]);
}

function paymentFor(Business $business, Customer $customer, User $user, string $amount, ?string $reversesId = null): Payment
{
    $payment = new Payment([
        'business_id' => $business->id,
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'payment_date' => '2026-07-17',
        'amount' => $amount,
        'mode' => 'cash',
        'reverses_id' => $reversesId,
    ]);
    $payment->created_by = $user->id;
    $payment->save();

    return $payment;
}

it('casts amount to a 2-decimal string and stamps version and sync_seq', function () {
    $business = Business::factory()->create();
    $customer = paymentCustomerFor($business);
    $user = User::factory()->create();

    $payment = paymentFor($business, $customer, $user, '1000.5');

    $fresh = $payment->fresh();
    expect($fresh->amount)->toBe('1000.50');
    expect($fresh->created_by)->toBe($user->id);
    expect($fresh->version)->toBe(1);
    expect($fresh->sync_seq)->toBeGreaterThan(0);
});

it('resolves a reversal back to the original payment it corrects', function () {
    $business = Business::factory()->create();
    $customer = paymentCustomerFor($business);
    $user = User::factory()->create();

    $original = paymentFor($business, $customer, $user, '500.00');
    $reversal = paymentFor($business, $customer, $user, '-500.00', $original->id);

    $fresh = Payment::with('reverses')->find($reversal->id);
    expect($fresh->reverses->id)->toBe($original->id);
    expect($fresh->amount)->toBe('-500.00');
});

it('sets the non-fillable created_by via the factory afterMaking hook', function () {
    $payment = Payment::factory()->make([
        'business_id' => (string) Str::uuid(),
        'customer_id' => (string) Str::uuid(),
    ]);

    expect($payment->created_by)->not->toBeNull();
});
