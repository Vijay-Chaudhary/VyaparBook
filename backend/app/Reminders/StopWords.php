<?php
// app/Reminders/StopWords.php

namespace App\Reminders;

use Illuminate\Support\Str;

/**
 * Does an inbound WhatsApp reply mean "stop messaging me"? (Phase 4b)
 *
 * Deliberately an EXACT match after normalising, never a substring test.
 * "please don't stop sending, I'll pay Friday" contains "stop" but is the
 * opposite of an opt-out, and silently unsubscribing that customer would lose
 * the shop a paying relationship. A false negative here costs one more
 * reminder; a false positive costs the debt.
 */
final class StopWords
{
    /** English and Hindi, matching the two locales reminders are sent in. */
    private const WORDS = [
        'stop', 'unsubscribe', 'cancel', 'opt out', 'optout',
        'बंद', 'बन्द', 'रोको', 'मत भेजो',
    ];

    public static function isStop(?string $body): bool
    {
        if ($body === null) {
            return false;
        }

        // Fold case, collapse whitespace, and drop trailing punctuation so
        // "STOP." and " stop " both count.
        $normalised = Str::of($body)
            ->lower()
            ->replaceMatches('/[\p{P}\p{S}]+/u', ' ')
            ->squish()
            ->value();

        return in_array($normalised, self::WORDS, strict: true);
    }
}
