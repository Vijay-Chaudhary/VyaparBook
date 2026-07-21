<?php
// tests/Unit/PlanCatalogTest.php

use App\Billing\PlanCatalog;

it('caps the free plan and grants it no features', function () {
    expect(PlanCatalog::maxCustomers('free'))->toBe(50);
    expect(PlanCatalog::maxUsers('free'))->toBe(1);
    expect(PlanCatalog::has('free', 'stock_production'))->toBeFalse();
});

it('makes pro customers unlimited with stock_production', function () {
    expect(PlanCatalog::maxCustomers('pro'))->toBeNull();
    expect(PlanCatalog::maxUsers('pro'))->toBe(5);
    expect(PlanCatalog::has('pro', 'stock_production'))->toBeTrue();
});

it('grants pro entitlement during trial', function () {
    expect(PlanCatalog::TRIAL_ENTITLEMENT)->toBe('pro');
});

it('throws on an unknown plan', function () {
    expect(fn () => PlanCatalog::limits('enterprise'))->toThrow(InvalidArgumentException::class);
});
