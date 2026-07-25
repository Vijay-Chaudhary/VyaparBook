<?php
// app/Orders/OrderStatus.php

namespace App\Orders;

/**
 * What an order may become next.
 *
 * Pure and DB-free: this is the rule every other part of the workflow leans on,
 * and a phone that has been offline for days will push transitions out of order
 * or twice. Forward-only, one step at a time, and never out of a terminal
 * state — the same discipline reminder_logs uses for delivery status.
 */
final class OrderStatus
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const PACKED = 'packed';

    public const DELIVERED = 'delivered';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** The linear path. Rank order is the only order these may be walked in. */
    private const RANK = [
        self::PENDING => 0,
        self::ACCEPTED => 1,
        self::PACKED => 2,
        self::DELIVERED => 3,
    ];

    private const TERMINAL = [self::DELIVERED, self::REJECTED, self::CANCELLED];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PENDING, self::ACCEPTED, self::PACKED, self::DELIVERED, self::REJECTED, self::CANCELLED];
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        // An order that is delivered, rejected or cancelled is finished. This
        // is what stops a late push from the field resurrecting it.
        if (self::isTerminal($from)) {
            return false;
        }

        if (! in_array($from, self::all(), true) || ! in_array($to, self::all(), true)) {
            return false;
        }

        // Cancellation is available from anywhere still open — a shop refusing
        // the goods at the door is the case this exists for.
        if ($to === self::CANCELLED) {
            return true;
        }

        // Rejection is the owner declining an order at acceptance, so it is
        // only reachable before acceptance.
        if ($to === self::REJECTED) {
            return $from === self::PENDING;
        }

        // Exactly one step forward. Skipping would let an order be delivered
        // that was never packed, which makes the packing state meaningless.
        return isset(self::RANK[$from], self::RANK[$to])
            && self::RANK[$to] === self::RANK[$from] + 1;
    }
}
