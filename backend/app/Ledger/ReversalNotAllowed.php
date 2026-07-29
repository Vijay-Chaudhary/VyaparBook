<?php
// app/Ledger/ReversalNotAllowed.php

namespace App\Ledger;

use RuntimeException;

/**
 * Why a correction was refused.
 *
 * Carries a machine-readable reason rather than only a message, because the two
 * callers must answer differently: the JSON API distinguishes 422 (this row is
 * itself a reversal) from 409 (this row already has one), and the Blade screen
 * shows a sentence. Collapsing both into one exception type would have forced
 * the API to change its status codes, which its tests pin.
 */
final class ReversalNotAllowed extends RuntimeException
{
    /** The row is itself a reversal. Reversing a reversal is a re-entry, not a correction. */
    public const IS_REVERSAL = 'is_reversal';

    /** A reversal already points at this row. */
    public const ALREADY_REVERSED = 'already_reversed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function isReversal(string $message): self
    {
        return new self(self::IS_REVERSAL, $message);
    }

    public static function alreadyReversed(string $message): self
    {
        return new self(self::ALREADY_REVERSED, $message);
    }
}
