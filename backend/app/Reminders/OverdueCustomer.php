<?php
// app/Reminders/OverdueCustomer.php

namespace App\Reminders;

/**
 * One row of the owner's overdue review list (Phase 4a).
 *
 * A customer who cannot be messaged is still represented here — with
 * $sendable false and a $blockedReason — rather than filtered out. Hiding them
 * would quietly under-report what the shop is owed, which is the opposite of
 * what this screen is for.
 */
final readonly class OverdueCustomer
{
    /**
     * @param  string       $customerId       uuid, for the send/opt-out routes
     * @param  string       $outstandingRupees scale-2 decimal string
     * @param  int          $daysOverdue      since last payment, or since first sale if never paid
     * @param  string|null  $lastPaymentOn    Y-m-d, null when never paid
     * @param  string|null  $phoneE164        normalised, null when unusable
     * @param  string|null  $blockedReason    'no_phone' | 'bad_phone' | 'opted_out'
     * @param  string|null  $lastReminderStatus  Phase 4b: queued|sent|delivered|read|failed, null if never reminded
     * @param  string|null  $lastRemindedAt      when that last reminder was recorded
     */
    public function __construct(
        public string $customerId,
        public string $name,
        public ?string $village,
        public ?string $phone,
        public ?string $phoneE164,
        public string $outstandingRupees,
        public int $daysOverdue,
        public ?string $lastPaymentOn,
        public bool $sendable,
        public ?string $blockedReason,
        public ?string $lastReminderStatus = null,
        public ?string $lastRemindedAt = null,
    ) {}
}
