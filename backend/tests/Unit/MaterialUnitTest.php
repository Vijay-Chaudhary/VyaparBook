<?php
// tests/Unit/MaterialUnitTest.php

use App\Stock\MaterialUnit;

it('exposes the canonical unit keys in order', function () {
    expect(MaterialUnit::keys())->toBe([
        'kg', 'gram', 'litre', 'ml', 'piece', 'packet', 'bag', 'dozen', 'tina',
    ]);
});

it('validates membership', function () {
    expect(MaterialUnit::isValid('kg'))->toBeTrue();
    expect(MaterialUnit::isValid('tina'))->toBeTrue();
    expect(MaterialUnit::isValid('furlong'))->toBeFalse();
    expect(MaterialUnit::isValid(''))->toBeFalse();
});

it('keeps ml, which predates this list', function () {
    // Removing it would orphan any existing row already stored as ml.
    expect(MaterialUnit::isValid('ml'))->toBeTrue();
});
