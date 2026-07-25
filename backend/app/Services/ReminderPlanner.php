<?php
// app/Services/ReminderPlanner.php

namespace App\Services;

use App\Models\Business;
use App\Models\ReminderBatch;
use App\Models\ReminderLog;
use App\Reminders\OverdueCustomer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds one day's automated reminder batch for one tenant (Phase 4c).
 *
 * Plans, never sends: it writes reminder_logs rows with status 'planned' so the
 * owner can see and cancel them before ReminderDispatcher acts. A planned row
 * IS the eventual message — there is no separate plan table that could drift
 * out of step with what was actually sent.
 *
 * Who is overdue is NOT re-decided here: that stays ReminderService's single
 * definition. This class only adds the restraints automation needs — the
 * cooldown and the daily cap.
 */
class ReminderPlanner
{
    public function __construct(private readonly ReminderService $reminders) {}

    /**
     * @return ReminderBatch|null  null when the tenant has automation off, or a
     *                             batch for this day already exists.
     */
    public function planFor(string $businessId, Carbon $date): ?ReminderBatch
    {
        $business = Business::query()->findOrFail($businessId);

        if (! $business->reminder_auto_enabled) {
            return null;
        }

        $scheduledFor = $date->toDateString();

        // Idempotency: cron double-fires, jobs retry, humans run commands by
        // hand. None of those may produce a second set of messages for a day.
        $existing = ReminderBatch::query()
            ->where('business_id', $businessId)
            ->where('scheduled_for', $scheduledFor)
            ->first();

        if ($existing !== null) {
            return null;
        }

        $cooldownDays = (int) $business->reminder_cooldown_days;
        $cap = (int) $business->reminder_daily_cap;

        // Already sorted biggest-debt-first, and already excludes anyone who
        // cannot be messaged (no phone, unusable number, opted out).
        $eligible = array_values(array_filter(
            $this->reminders->overdue($businessId),
            fn (OverdueCustomer $row) => $row->sendable
                && ! $this->inCooldown($businessId, $row->customerId, $cooldownDays, $date),
        ));

        // The cap spends on the biggest money first, and the remainder simply
        // waits for tomorrow's run rather than being dropped.
        $selected = array_slice($eligible, 0, $cap);
        $stoppedReason = count($eligible) > $cap ? 'daily_cap' : null;

        return DB::transaction(function () use ($businessId, $business, $scheduledFor, $selected, $stoppedReason) {
            $batch = ReminderBatch::create([
                'business_id' => $businessId,
                'scheduled_for' => $scheduledFor,
                'status' => 'planned',
                'planned_count' => count($selected),
                'stopped_reason' => $stoppedReason,
            ]);

            $locale = $business->default_language ?? config('app.locale');

            foreach ($selected as $row) {
                $log = new ReminderLog([
                    'business_id' => $businessId,
                    'customer_id' => $row->customerId,
                    'channel' => 'cloud_api',
                    'amount_at_send' => $row->outstandingRupees,
                    'locale' => $locale,
                    'phone_e164' => $row->phoneE164,
                    'batch_id' => $batch->id,
                ]);
                $log->status = 'planned';
                // The scheduler acts on the tenant's behalf; there is no user.
                $log->created_by = null;
                $log->save();
            }

            return $batch;
        });
    }

    /**
     * Was this customer AUTO-reminded recently?
     *
     * Only automated history counts (batch_id is not null). An owner who tapped
     * Remind yesterday made a deliberate human decision about that customer;
     * that must not silently switch off the machine's own restraint for them.
     */
    private function inCooldown(string $businessId, string $customerId, int $cooldownDays, Carbon $date): bool
    {
        if ($cooldownDays <= 0) {
            return false;
        }

        return ReminderLog::query()
            ->where('business_id', $businessId)
            ->where('customer_id', $customerId)
            ->whereNotNull('batch_id')
            ->where('created_at', '>=', $date->copy()->subDays($cooldownDays))
            ->exists();
    }
}
