<?php
// app/Support/Inr.php

namespace App\Support;

/**
 * Format a decimal rupee string with Indian digit grouping: ₹1,20,000.50.
 *
 * Not Western 120,000.50 — Indian grouping is 2,2,3 after the first three
 * digits, and getting it wrong makes the number read as a different amount to
 * the person whose money it is. Mirrors resources/js/offline/money.js
 * formatRupees so the printed report and the phone agree.
 *
 * Input is a bcmath-scale-2 decimal string (what the service produces); no
 * floats are involved. `intl` is not required — grouping is done by hand.
 */
class Inr
{
    public static function format(string $amount, bool $withSymbol = true): string
    {
        $negative = str_starts_with($amount, '-');
        $abs = ltrim($amount, '-');

        // Normalise to exactly two decimals via bcadd at scale 2 (truncates,
        // matching the server's bcmath discipline — never rounds up).
        $normalised = bcadd($abs === '' ? '0' : $abs, '0', 2);
        if ($normalised === '0.00') {
            $negative = false;
        }
        [$whole, $frac] = explode('.', $normalised);

        $grouped = self::groupIndian($whole);
        $sign = $negative ? '−' : '';          // U+2212 minus, like money.js
        $symbol = $withSymbol ? '₹' : '';

        return "{$sign}{$symbol}{$grouped}.{$frac}";
    }

    /**
     * Compact rupee label for chart axes: ₹8.3K, ₹2.6L, ₹1.5Cr. Display-only
     * (float division is fine here — this never touches a stored figure). Uses
     * Indian scale words (K = thousand, L = lakh, Cr = crore) and the same U+2212
     * minus and ₹ as format().
     */
    public static function abbreviate(string $amount, bool $withSymbol = true): string
    {
        $negative = str_starts_with($amount, '-');
        $n = (float) ltrim($amount, '-');
        $symbol = $withSymbol ? '₹' : '';
        $sign = $negative ? '−' : '';

        $trim = fn (string $s) => rtrim(rtrim($s, '0'), '.');

        if ($n >= 10000000) {
            $body = $trim(number_format($n / 10000000, 1, '.', '')) . 'Cr';
        } elseif ($n >= 100000) {
            $body = $trim(number_format($n / 100000, 1, '.', '')) . 'L';
        } elseif ($n >= 1000) {
            $body = $trim(number_format($n / 1000, 1, '.', '')) . 'K';
        } else {
            $body = $trim(number_format($n, 2, '.', '')) ?: '0';
        }

        return "{$sign}{$symbol}{$body}";
    }

    private static function groupIndian(string $whole): string
    {
        if (strlen($whole) <= 3) {
            return $whole;
        }

        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);
        // Group the remaining digits in pairs, from the right.
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);

        return "{$rest},{$last3}";
    }
}
