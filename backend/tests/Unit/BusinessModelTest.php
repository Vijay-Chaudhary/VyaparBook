<?php
// tests/Unit/BusinessModelTest.php

use App\Models\Business;

it('generates a uuid primary key on create', function () {
    $business = Business::factory()->connection('pgsql_migrate')->create();

    expect($business->id)->toBeString();
    expect(strlen($business->id))->toBe(36);
});
