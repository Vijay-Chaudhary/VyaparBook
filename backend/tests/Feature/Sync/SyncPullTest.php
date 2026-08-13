<?php
// tests/Feature/Sync/SyncPullTest.php

use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

function pullSetup(): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => 'owner',
    ]);

    return [$business, $user, (new TokenService())->issue($user, $membership)];
}

function seedPullCustomer(Business $business, string $name): Customer
{
    return Customer::create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(), 'name' => $name,
    ]);
}

function seedPullPayment(Customer $customer, User $user, string $amount): Payment
{
    $payment = new Payment([
        'business_id' => $customer->business_id, 'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id, 'payment_date' => '2026-07-17', 'amount' => $amount, 'mode' => 'cash',
    ]);
    $payment->created_by = $user->id;
    $payment->save();

    return $payment;
}

function pull(string $token, int $since = 0)
{
    return test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/sync/pull?since={$since}");
}

it('returns all the tenant rows and a positive cursor on an initial pull', function () {
    [$business, $user, $token] = pullSetup();
    $customer = seedPullCustomer($business, 'Ram Traders');
    seedPullPayment($customer, $user, '200.00');

    $response = pull($token, 0)
        ->assertOk()
        ->assertJsonCount(1, 'customers')
        ->assertJsonCount(1, 'payments');

    expect($response->json('cursor'))->toBeGreaterThan(0);
});

it('returns only rows changed since the cursor', function () {
    [$business, $user, $token] = pullSetup();
    $customer = seedPullCustomer($business, 'Ram Traders');
    seedPullPayment($customer, $user, '200.00');

    $cursor = pull($token, 0)->json('cursor');

    // One new payment after the cursor.
    $newPayment = seedPullPayment($customer, $user, '50.00');

    $response = pull($token, $cursor)
        ->assertOk()
        ->assertJsonCount(0, 'customers')
        ->assertJsonCount(1, 'payments');

    expect($response->json('payments.0.id'))->toBe($newPayment->id);
    expect($response->json('cursor'))->toBeGreaterThan($cursor);
});

it('holds a stable cursor and empty delta when nothing changed', function () {
    [$business, $user, $token] = pullSetup();
    $customer = seedPullCustomer($business, 'Ram Traders');
    seedPullPayment($customer, $user, '200.00');

    $cursor = pull($token, 0)->json('cursor');

    $response = pull($token, $cursor)
        ->assertOk()
        ->assertJsonCount(0, 'customers')
        ->assertJsonCount(0, 'payments');

    expect($response->json('cursor'))->toBe($cursor);
});

it('never returns another tenant rows regardless of cursor', function () {
    [$mine, $user, $token] = pullSetup();
    $theirs = Business::factory()->create();
    $theirUser = User::factory()->create();
    $theirCustomer = seedPullCustomer($theirs, 'Their Customer');
    seedPullPayment($theirCustomer, $theirUser, '999.00');

    // My own row so the pull is not trivially empty.
    seedPullCustomer($mine, 'My Customer');

    $response = pull($token, 0)->assertOk()->assertJsonCount(1, 'customers');

    expect($response->json('customers.0.name'))->toBe('My Customer');
    expect($response->json('payments'))->toBe([]);
});

it('includes an archived row in the delta so the client learns to hide it', function () {
    [$business, $user, $token] = pullSetup();
    $customer = seedPullCustomer($business, 'Ram Traders');

    $cursor = pull($token, 0)->json('cursor');

    $customer->archived_at = now();
    $customer->save(); // bumps sync_seq past the cursor

    $response = pull($token, $cursor)->assertOk()->assertJsonCount(1, 'customers');
    expect($response->json('customers.0.archived_at'))->not->toBeNull();
});

it('includes a deleted payment in the delta, for the same reason', function () {
    // The owner deletes a khata row from the console. A device learns about
    // rows by being sent them, never by being told one vanished — so if the
    // SoftDeletes scope quietly dropped it from the delta, every phone would
    // keep counting a payment the shop deleted.
    [$business, $user, $token] = pullSetup();
    $customer = seedPullCustomer($business, 'Ram Traders');
    $payment = seedPullPayment($customer, $user, '200.00');

    $cursor = pull($token, 0)->json('cursor');

    asTenant($business->id, fn () => app(App\Ledger\LedgerEditor::class)
        ->deletePayment(App\Models\Payment::find($payment->id)));

    $response = pull($token, $cursor)->assertOk()->assertJsonCount(1, 'payments');
    expect($response->json('payments.0.deleted_at'))->not->toBeNull();
});
