<?php
// tests/Unit/ExpenseCategoryTest.php

use App\Expenses\ExpenseCategory;

it('exposes the canonical category keys in order', function () {
    expect(ExpenseCategory::keys())->toBe([
        'rent', 'salaries', 'electricity', 'diesel', 'transport',
        'packing_material', 'maintenance', 'other',
    ]);
});

it('validates membership', function () {
    expect(ExpenseCategory::isValid('rent'))->toBeTrue();
    expect(ExpenseCategory::isValid('diesel'))->toBeTrue();
    expect(ExpenseCategory::isValid('groceries'))->toBeFalse();
    expect(ExpenseCategory::isValid(''))->toBeFalse();
});

it('knows which categories require a note', function () {
    expect(ExpenseCategory::requiresNote('other'))->toBeTrue();
    expect(ExpenseCategory::requiresNote('rent'))->toBeFalse();
});

it('has a label for every key in both languages', function () {
    // A missing Hindi label is invisible until someone switches language, by
    // which point it renders as the raw translation key at a shopkeeper.
    foreach (['en', 'hi'] as $locale) {
        $labels = require base_path("lang/{$locale}/expenses.php");

        foreach (ExpenseCategory::keys() as $key) {
            expect($labels['categories'][$key] ?? null)
                ->not->toBeNull("missing {$locale} label for '{$key}'");
        }
    }
});
