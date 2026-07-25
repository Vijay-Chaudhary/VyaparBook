<?php

use App\Gst\GstCalculator;

/** The invariant everything else rests on: the invoice cannot disagree with the sale. */
function assertReconciles(string $lineTotal, string $rate): void
{
    $split = GstCalculator::extract($lineTotal, $rate);
    $sum = bcadd(bcadd($split->taxableValue, $split->cgst, 2), $split->sgst, 2);

    expect($sum)->toBe($lineTotal);
    expect(bcadd($split->cgst, $split->sgst, 2))->toBe($split->tax);
}

it('extracts tax from a GST-inclusive amount', function () {
    // ₹105.00 at 5% inclusive → ₹100.00 taxable, ₹5.00 tax, split in half.
    $split = GstCalculator::extract('105.00', '5.00');

    expect($split->taxableValue)->toBe('100.00');
    expect($split->tax)->toBe('5.00');
    expect($split->cgst)->toBe('2.50');
    expect($split->sgst)->toBe('2.50');
});

it('treats a zero rate as exempt rather than dividing by one and guessing', function () {
    $split = GstCalculator::extract('250.00', '0.00');

    expect($split->taxableValue)->toBe('250.00');
    expect($split->tax)->toBe('0.00');
    expect($split->cgst)->toBe('0.00');
    expect($split->sgst)->toBe('0.00');
});

it('reconciles exactly on awkward amounts at every common rate', function () {
    foreach (['0.00', '5.00', '12.00', '18.00', '28.00'] as $rate) {
        foreach (['99.99', '1.00', '0.01', '33.33', '1234.56', '7.77'] as $amount) {
            assertReconciles($amount, $rate);
        }
    }
});

it('gives an odd paisa to CGST so the halves still sum to the tax', function () {
    // Chosen so the tax is an odd number of paise and cannot halve evenly.
    $split = GstCalculator::extract('99.99', '5.00');

    expect($split->taxableValue)->toBe('95.23');
    expect($split->tax)->toBe('4.76');
    expect(bcadd($split->cgst, $split->sgst, 2))->toBe('4.76');
});

it('carries a return through as negative values that still reconcile', function () {
    $split = GstCalculator::extract('-105.00', '5.00');

    expect($split->taxableValue)->toBe('-100.00');
    expect($split->tax)->toBe('-5.00');
    assertReconciles('-105.00', '5.00');
});

it('rounds the taxable value half-up rather than truncating', function () {
    // 1.00 ÷ 1.18 = 0.847457… → 0.85, not 0.84.
    expect(GstCalculator::extract('1.00', '18.00')->taxableValue)->toBe('0.85');
});
