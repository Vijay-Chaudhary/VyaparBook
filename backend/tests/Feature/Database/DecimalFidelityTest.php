<?php
// tests/Feature/Database/DecimalFidelityTest.php

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The smallest test in the suite, guarding the largest thing.
 *
 * Every rupee is a decimal STRING run through bcmath so money never touches a
 * float. Postgres returned DECIMAL as a string; MySQL does too, but ONLY with
 * native prepares. Turn on PDO::ATTR_EMULATE_PREPARES and decimals arrive as
 * floats: nothing throws, and khatas drift by paise over months.
 */

it('returns DECIMAL columns as PHP strings, not floats', function () {
    $row = DB::select('SELECT CAST(1234.56 AS DECIMAL(12,2)) AS d')[0];

    expect($row->d)->toBeString();
});

it('round-trips a stored money column without float drift', function () {
    $b = Business::factory()->create();
    app()->bind('tenant.id', fn () => $b->id);

    Customer::create([
        'business_id' => $b->id, 'uuid' => (string) Str::uuid(),
        'name' => 'Ram', 'opening_balance' => '99999999.99',
    ]);

    $raw = DB::table('customers')->where('business_id', $b->id)->value('opening_balance');

    expect($raw)->toBeString();
    // Exact string equality: a float round-trip would land on 100000000.0 or
    // 99999999.990000001 and this would fail loudly.
    expect((string) $raw)->toBe('99999999.99');
});

it('has emulated prepares disabled on the app connection', function () {
    // Belt and braces: the two tests above would start passing again by
    // accident if someone re-enabled emulation and MySQL happened to return a
    // string. This asserts the setting itself.
    $pdo = DB::connection()->getPdo();

    expect($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES))->toBeFalsy();
});
