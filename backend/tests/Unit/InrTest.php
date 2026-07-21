<?php
// tests/Unit/InrTest.php

use App\Support\Inr;

it('formats with Indian grouping and two decimals', function () {
    expect(Inr::format('264004.00'))->toBe('₹2,64,004.00');
    expect(Inr::format('107963'))->toBe('₹1,07,963.00');
    expect(Inr::format('999.5'))->toBe('₹999.50');
    expect(Inr::format('0'))->toBe('₹0.00');
    expect(Inr::format('1234'))->toBe('₹1,234.00');
});

it('shows negatives with a leading minus, not parentheses', function () {
    expect(Inr::format('-26504'))->toBe('−₹26,504.00');
});

it('can omit the symbol', function () {
    expect(Inr::format('1200000', withSymbol: false))->toBe('12,00,000.00');
});
