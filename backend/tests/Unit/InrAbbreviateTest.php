<?php
// tests/Unit/InrAbbreviateTest.php

use App\Support\Inr;

it('abbreviates rupee amounts for axis labels', function () {
    expect(Inr::abbreviate('8272.00'))->toBe('₹8.3K');
    expect(Inr::abbreviate('264004.00'))->toBe('₹2.6L');
    expect(Inr::abbreviate('15000000'))->toBe('₹1.5Cr');
    expect(Inr::abbreviate('999.50'))->toBe('₹999.5');
    expect(Inr::abbreviate('0'))->toBe('₹0');
});

it('shows a leading minus for negative amounts and can omit the symbol', function () {
    expect(Inr::abbreviate('-15420.00'))->toBe('−₹15.4K');
    expect(Inr::abbreviate('5000', withSymbol: false))->toBe('5K');
});
