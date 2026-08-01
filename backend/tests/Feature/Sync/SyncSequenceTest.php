<?php
// tests/Feature/Sync/SyncSequenceTest.php

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A customer written under an explicit tenant. */
function seqCustomer(Business $b, string $name): Customer
{
    return Customer::create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => $name, 'opening_balance' => '0.00',
    ]);
}

it('hands out strictly increasing sync_seq within a tenant', function () {
    $b = Business::factory()->create();

    $first = seqCustomer($b, 'One');
    $second = seqCustomer($b, 'Two');

    expect($second->sync_seq)->toBeGreaterThan($first->sync_seq);
});

it('advances on update as well as insert, so a delta pull sees the change', function () {
    $b = Business::factory()->create();
    $c = seqCustomer($b, 'One');
    $afterInsert = $c->sync_seq;

    $c->name = 'One Renamed';
    $c->save();

    expect($c->sync_seq)->toBeGreaterThan($afterInsert);
});

it('counts each tenant independently, so one shop cannot exhaust another', function () {
    // The whole point of per-tenant counters: contention and numbering are
    // scoped to a shop, not shared platform-wide.
    $a = Business::factory()->create();
    $b = Business::factory()->create();

    seqCustomer($a, 'A1');
    seqCustomer($a, 'A2');
    $firstOfB = seqCustomer($b, 'B1');

    expect($firstOfB->sync_seq)->toBe(1);
});

it('creates the counter row on demand rather than needing business creation to seed it', function () {
    // Self-healing: a business created before this table existed, or by a
    // seeder that bypassed a hook, still gets a working counter.
    $b = Business::factory()->create();
    DB::table('sync_sequences')->where('business_id', $b->id)->delete();

    $c = seqCustomer($b, 'One');

    expect($c->sync_seq)->toBe(1);
});
