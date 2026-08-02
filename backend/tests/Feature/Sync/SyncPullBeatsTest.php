<?php
// tests/Feature/Sync/SyncPullBeatsTest.php

use App\Models\Beat;
use App\Models\BeatCustomer;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Str;

/** @return array{0: Business, 1: User, 2: string} */
function beatPullSetup(string $role = 'owner'): array
{
    $business = Business::factory()->create();
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);

    return [$business, $user, (new TokenService())->issue($user, $membership)];
}

/** A user added to an EXISTING business, so two roles can share one tenant. */
function beatMember(Business $business, string $role): array
{
    $user = User::factory()->create();
    $membership = Membership::create([
        'user_id' => $user->id, 'business_id' => $business->id, 'role' => $role,
    ]);

    return [$user, (new TokenService())->issue($user, $membership)];
}

function seedBeat(Business $business, string $name, array $weekdays, ?int $userId): Beat
{
    $beat = Beat::create([
        'business_id' => $business->id, 'name' => $name,
        'weekdays' => $weekdays, 'assigned_user_id' => $userId,
    ]);

    $customer = Customer::create([
        'business_id' => $business->id, 'uuid' => (string) Str::uuid(),
        'name' => $name.' Customer', 'village' => 'Rampur', 'opening_balance' => '0.00',
    ]);

    BeatCustomer::create([
        'business_id' => $business->id, 'beat_id' => $beat->id,
        'customer_id' => $customer->id, 'position' => 1,
    ]);

    return $beat;
}

function pullBeats(string $token, int $since = 0)
{
    return test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/sync/pull?since={$since}");
}

it('streams beats and their customers to a manager', function () {
    [$business, , $token] = beatPullSetup();
    seedBeat($business, 'Rampur', [1, 4], null);

    $response = pullBeats($token)->assertOk();

    expect($response->json('beats'))->toHaveCount(1);
    expect($response->json('beats.0.name'))->toBe('Rampur');
    expect($response->json('beats.0.weekdays'))->toBe([1, 4]);
    expect($response->json('beat_customers'))->toHaveCount(1);
});

it('gives a salesman only the beats assigned to them', function () {
    [$business, $owner] = beatPullSetup();
    [$salesman, $salesmanToken] = beatMember($business, 'salesman');

    seedBeat($business, 'Mine', [1], $salesman->id);
    seedBeat($business, 'Theirs', [1], $owner->id);

    $response = pullBeats($salesmanToken)->assertOk();

    // Another salesman's route is not their business.
    expect($response->json('beats'))->toHaveCount(1);
    expect($response->json('beats.0.name'))->toBe('Mine');
    // Membership rows follow their beat, so nothing on the device dangles.
    expect($response->json('beat_customers'))->toHaveCount(1);
});

it('never streams another tenant\'s beats', function () {
    [$business, , $token] = beatPullSetup();
    [$other] = beatPullSetup();

    seedBeat($business, 'Mine', [1], null);
    seedBeat($other, 'Theirs', [1], null);

    $response = pullBeats($token)->assertOk();

    expect($response->json('beats'))->toHaveCount(1);
    expect($response->json('beats.0.name'))->toBe('Mine');
});

it('advances the cursor so a second pull is an empty delta', function () {
    [$business, , $token] = beatPullSetup();
    seedBeat($business, 'Rampur', [1], null);

    $cursor = pullBeats($token)->assertOk()->json('cursor');
    $second = pullBeats($token, (int) $cursor)->assertOk();

    expect($second->json('beats'))->toBe([]);
    expect($second->json('beat_customers'))->toBe([]);
});
