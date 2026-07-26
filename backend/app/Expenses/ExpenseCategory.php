<?php
// app/Expenses/ExpenseCategory.php

namespace App\Expenses;

/**
 * Single source of truth for operating-expense categories. The request
 * validator, the Blade dropdown and the dashboard breakdown all read this list,
 * so it is defined exactly once. Display labels live in lang/{en,hi}/expenses.php,
 * keyed by these slugs.
 *
 * Operating expenses only — never stock/raw-material purchases (those are Phase
 * 2 and would double-count against estimated product cost).
 */
final class ExpenseCategory
{
    /** @return list<string> canonical order, used everywhere the list renders. */
    public static function keys(): array
    {
        return [
            'rent', 'salaries', 'electricity', 'diesel', 'transport',
            'packing_material', 'maintenance', 'other',
        ];
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** `other` is a catch-all, so a note is expected to say what it was. */
    public static function requiresNote(string $key): bool
    {
        return $key === 'other';
    }
}
