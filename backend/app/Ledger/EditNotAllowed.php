<?php
// app/Ledger/EditNotAllowed.php

namespace App\Ledger;

use RuntimeException;

/**
 * Why an edit, delete or restore was refused.
 *
 * Shaped like its sibling ReversalNotAllowed: a machine-readable reason plus a
 * sentence, so a caller can branch on the reason while the Blade screen just
 * prints the message. Today only the console calls LedgerEditor and only ever
 * prints, but the reason is what a future JSON caller would need to map to a
 * status code — and retro-fitting one onto a message-only exception means
 * string-matching the message, which is how these drift.
 */
final class EditNotAllowed extends RuntimeException
{
    /** The row is half of a reversal pair, so its figures mirror another row's. */
    public const IS_REVERSAL = 'is_reversal';

    /** A reversal already points at this row; the pair nets to zero as it stands. */
    public const HAS_REVERSAL = 'has_reversal';

    /** A tax invoice was issued for this sale, and it is already in the customer's hands. */
    public const INVOICED = 'invoiced';

    /** The sale came from an order, which owns the figures. */
    public const FROM_ORDER = 'from_order';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function isReversal(string $message): self
    {
        return new self(self::IS_REVERSAL, $message);
    }

    public static function hasReversal(string $message): self
    {
        return new self(self::HAS_REVERSAL, $message);
    }

    public static function invoiced(string $message): self
    {
        return new self(self::INVOICED, $message);
    }

    public static function fromOrder(string $message): self
    {
        return new self(self::FROM_ORDER, $message);
    }
}
