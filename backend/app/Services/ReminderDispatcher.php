<?php
// app/Services/ReminderDispatcher.php

namespace App\Services;

use App\Jobs\SendReminderJob;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ReminderBatch;
use App\Models\ReminderLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Releases a planned batch to the queue, once every safety condition still
 * holds (Phase 4c).
 *
 * Everything here is a RE-check. The planner ran hours earlier, and in between
 * the shop may have switched automation off, the customer may have paid or
 * opted out, and the clock may have left the hours when it is acceptable to
 * message someone. Re-checking at the moment of sending is the difference
 * between automation and a machine acting on stale beliefs.
 *
 * Every refusal is recorded — a run that sends nothing must always be able to
 * say why, or the owner cannot tell "working, nothing to do" from "broken".
 */
class ReminderDispatcher
{
    /** Nobody is messaged before 09:00 or after 20:00, whatever a tenant sets. */
    public const QUIET_START_HOUR = 9;

    public const QUIET_END_HOUR = 20;

    public function __construct(private readonly KhataService $khata) {}

    public function dispatchFor(string $businessId, Carbon $now): void
    {
        $batch = ReminderBatch::query()
            ->where('business_id', $businessId)
            ->where('scheduled_for', $now->toDateString())
            ->where('status', 'planned')
            ->first();

        if ($batch === null) {
            return;
        }

        $business = Business::query()->findOrFail($businessId);

        if (! $business->reminder_auto_enabled) {
            $this->stop($batch, 'automation_off');

            return;
        }

        // The precondition from the spec: never automate through a transport
        // that has not been proven against the real API.
        if (config('services.whatsapp.driver') !== 'cloud_api') {
            $this->stop($batch, 'transport_disabled');

            return;
        }

        if ($now->hour < self::QUIET_START_HOUR || $now->hour >= self::QUIET_END_HOUR) {
            // Not a failure — just not now. The batch stays planned and the next
            // tick inside the window picks it up.
            $this->stop($batch, 'quiet_hours', keepPlanned: true);

            return;
        }

        // The tenant's chosen moment inside that window has not arrived yet.
        if ($now->format('H:i:s') < (string) $business->reminder_send_at) {
            return;
        }

        $sent = 0;

        foreach ($this->plannedRows($businessId, $batch->id) as $log) {
            if ($this->shouldSkip($businessId, $log, $business)) {
                $log->status = 'skipped';
                $log->status_at = $now;
                $log->save();

                continue;
            }

            $log->status = 'queued';
            $log->status_at = $now;
            $log->save();

            SendReminderJob::dispatch($log->id, $businessId);
            $sent++;
        }

        $batch->status = 'sent';
        $batch->sent_count = $sent;
        $batch->save();
    }

    /** @return \Illuminate\Support\Collection<int, ReminderLog> */
    private function plannedRows(string $businessId, string $batchId)
    {
        return ReminderLog::query()
            ->where('business_id', $businessId)
            ->where('batch_id', $batchId)
            ->where('status', 'planned')   // a cancelled row is already excluded
            ->get();
    }

    /**
     * The two things that can have changed under us for a single customer:
     * they paid, or they asked us to stop. Both mean this message must not go.
     */
    private function shouldSkip(string $businessId, ReminderLog $log, Business $business): bool
    {
        $customer = Customer::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->find($log->customer_id);

        if ($customer === null || $customer->reminder_opt_out_at !== null) {
            return true;
        }

        // Chasing someone for money they have already paid is the worst bug
        // this feature could have, so outstanding is re-derived, not trusted
        // from planning time.
        $outstanding = $this->khata->outstandingFor($customer);

        return bccomp($outstanding, (string) $business->reminder_min_outstanding, 2) < 0;
    }

    private function stop(ReminderBatch $batch, string $reason, bool $keepPlanned = false): void
    {
        DB::transaction(function () use ($batch, $reason, $keepPlanned) {
            $batch->stopped_reason = $reason;
            // quiet_hours is temporary: leave the batch planned so a later tick
            // inside the window can still send it. Everything else is terminal
            // for the day.
            $batch->status = $keepPlanned ? 'planned' : 'skipped';
            $batch->save();
        });
    }
}
