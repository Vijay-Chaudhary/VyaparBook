<?php
// tests/Unit/ExpenseCategoryTest.php

use App\Expenses\ExpenseCategory;

it('exposes the canonical category keys in order', function () {
    expect(ExpenseCategory::keys())->toBe([
        'rent', 'salaries', 'electricity', 'transport', 'maintenance', 'other',
    ]);
});

it('validates membership', function () {
    expect(ExpenseCategory::isValid('rent'))->toBeTrue();
    expect(ExpenseCategory::isValid('groceries'))->toBeFalse();
    expect(ExpenseCategory::isValid(''))->toBeFalse();
});

it('knows which categories require a note', function () {
    expect(ExpenseCategory::requiresNote('other'))->toBeTrue();
    expect(ExpenseCategory::requiresNote('rent'))->toBeFalse();
});
