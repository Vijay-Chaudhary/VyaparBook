<?php
// tests/Unit/MarginTest.php

use App\Pricing\Margin;

describe('amount', function () {
    it('is what the pack makes over its cost', function () {
        expect(Margin::amount('100.00', '93.00'))->toBe('7.00');
    });

    it('goes negative on a pack sold under cost, rather than clamping at zero', function () {
        // Half this shop's catalog is here at its true costs. A clamped zero
        // would hide exactly the rows the screen exists to show.
        expect(Margin::amount('110.00', '117.00'))->toBe('-7.00');
    });

    it('is unknown when the pack has no cost basis', function () {
        // Not zero: zero would read as "sold at pure profit" in the column.
        expect(Margin::amount('100.00', null))->toBeNull();
    });
});

describe('percent', function () {
    it('is margin over the SELLING price, not markup on cost', function () {
        // Bought at 80, sold at 100 → 20% margin, though it is 25% markup.
        // A P&L reads margin on revenue, so that is what this reports.
        expect(Margin::percent('100.00', '80.00'))->toBe('20.0');
    });

    it('reports a loss as a negative percentage', function () {
        // -6.3636…% truncated toward zero, not rounded: bcmath truncates, and
        // the codebase keeps that convention rather than mixing the two. The
        // exact rupee amount is the authoritative figure beside it.
        expect(Margin::percent('110.00', '117.00'))->toBe('-6.3');
    });

    it('keeps one decimal place without truncating it away', function () {
        // 93 cost on a 100 sale is 7.0%; the intermediate division must not
        // truncate before the multiply or this lands on 0.0.
        expect(Margin::percent('100.00', '93.00'))->toBe('7.0');
        expect(Margin::percent('39.00', '32.50'))->toBe('16.6');
    });

    it('refuses to divide by a zero selling price', function () {
        // A free issue has no percentage. Saying so beats blowing up.
        expect(Margin::percent('0.00', '10.00'))->toBeNull();
    });

    it('is unknown when the cost is unknown', function () {
        expect(Margin::percent('100.00', null))->toBeNull();
    });
});

describe('isLoss', function () {
    it('is true only below cost, not at it', function () {
        expect(Margin::isLoss('69.99', '70.00'))->toBeTrue();
        // Selling exactly at cost is not a loss — the same boundary PriceFloor
        // has always drawn, where rate == floor is allowed.
        expect(Margin::isLoss('70.00', '70.00'))->toBeFalse();
        expect(Margin::isLoss('70.01', '70.00'))->toBeFalse();
    });

    it('does not call an unknown cost a loss', function () {
        expect(Margin::isLoss('10.00', null))->toBeFalse();
    });
});
