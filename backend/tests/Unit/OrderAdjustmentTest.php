<?php
// tests/Unit/OrderAdjustmentTest.php

use App\Models\OrderLine;
use App\Orders\OrderAdjustment;

/** A line as it exists after acceptance: originals stamped, live values current. */
function adjLine(?int $orderedQty, ?string $orderedRate, int $qty, string $rate): OrderLine
{
    $line = new OrderLine;
    $line->ordered_qty = $orderedQty;
    $line->ordered_rate = $orderedRate;
    $line->qty = $qty;
    $line->rate = $rate;

    return $line;
}

describe('changed', function () {
    it('sees a quantity the owner cut', function () {
        expect(OrderAdjustment::changed(adjLine(10, '90.00', 8, '90.00')))->toBeTrue();
    });

    it('sees a rate the owner raised', function () {
        expect(OrderAdjustment::changed(adjLine(10, '90.00', 10, '95.00')))->toBeTrue();
    });

    it('sees both moving at once', function () {
        expect(OrderAdjustment::changed(adjLine(10, '90.00', 8, '95.00')))->toBeTrue();
    });

    it('reports nothing when acceptance left the line alone', function () {
        expect(OrderAdjustment::changed(adjLine(10, '90.00', 10, '90.00')))->toBeFalse();
    });

    it('does not mistake the same money written at another precision for an edit', function () {
        // Decimal casts stringify at differing precision. A string comparison
        // would report '90.0' → '90.00' as a renegotiation that never happened.
        expect(OrderAdjustment::changed(adjLine(10, '90.0', 10, '90.00')))->toBeFalse();
    });

    it('stays silent when the original is unknown, rather than guessing', function () {
        // Null means the line predates the audit trail. Reporting a change
        // would invent one; reporting "unchanged" is what the UI already shows
        // for a line nobody touched, so silence is the honest answer.
        expect(OrderAdjustment::changed(adjLine(null, null, 8, '95.00')))->toBeFalse();
        expect(OrderAdjustment::changed(adjLine(10, null, 8, '95.00')))->toBeFalse();
        expect(OrderAdjustment::changed(adjLine(null, '90.00', 8, '95.00')))->toBeFalse();
    });
});

describe('anyChanged', function () {
    it('is true when a single line moved among many that did not', function () {
        expect(OrderAdjustment::anyChanged([
            adjLine(2, '50.00', 2, '50.00'),
            adjLine(10, '90.00', 8, '90.00'),
            adjLine(1, '20.00', 1, '20.00'),
        ]))->toBeTrue();
    });

    it('is false for an order accepted exactly as it was taken', function () {
        expect(OrderAdjustment::anyChanged([
            adjLine(2, '50.00', 2, '50.00'),
            adjLine(1, '20.00', 1, '20.00'),
        ]))->toBeFalse();
    });

    it('is false for no lines at all', function () {
        expect(OrderAdjustment::anyChanged([]))->toBeFalse();
    });
});

describe('originalTotal', function () {
    it('totals the order as it was taken, not as it was approved', function () {
        expect(OrderAdjustment::originalTotal([
            adjLine(10, '90.00', 8, '95.00'),
            adjLine(2, '50.00', 2, '50.00'),
        ]))->toBe('1000.00');
    });

    it('refuses to total a set where any original is unknown', function () {
        // All-or-nothing: mixing originals with post-acceptance values gives a
        // figure that was never true, shown beside the accepted total where
        // someone would subtract the two.
        expect(OrderAdjustment::originalTotal([
            adjLine(10, '90.00', 8, '95.00'),
            adjLine(null, null, 2, '50.00'),
        ]))->toBeNull();
    });

    it('totals an empty order to zero', function () {
        expect(OrderAdjustment::originalTotal([]))->toBe('0.00');
    });
});
