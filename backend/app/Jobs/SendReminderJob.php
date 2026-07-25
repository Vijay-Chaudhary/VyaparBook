<?php
// app/Jobs/SendReminderJob.php

namespace App\Jobs;

use App\Models\Business;
use App\Models\Customer;
use App\Models\ReminderLog;
use App\Reminders\Contracts\WhatsAppSender;
use App\Reminders\ReminderMessage;
use App\Support\Inr;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivers one reminder through the configured transport (Phase 4b).
 *
 * Queued rather than inline: a Cloud API call is a round-trip to a third party,
 * and doing it in the request would make the owner wait on Meta and lose the
 * send on a timeout. The reminder_logs row is written by the controller BEFORE
 * dispatch, so a reminder is never invisible just because a worker is behind.
 *
 * Carries ids, not models: a serialised model would drag a tenant-scoped query
 * into the queue payload, and a worker has no request or tenant context to
 * resolve it with — this job pins the tenant itself.
 */
class SendReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $reminderLogId,
        public readonly string $businessId,
    ) {}

    /** Exponential-ish backoff: a Meta blip should not burn all three tries in a second. */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(WhatsAppSender $sender): void
    {
        // A queue worker has no request, so nothing has pinned the tenant.
        DB::transaction(function () use ($sender) {
            TenantContext::switchTo($this->businessId);
            app()->bind('tenant.id', fn () => $this->businessId);

            $log = ReminderLog::query()
                ->where('business_id', $this->businessId)
                ->find($this->reminderLogId);

            // Only ever act on a row still waiting to go out. A replayed or
            // duplicated job must not send the customer a second message.
            if ($log === null || $log->status !== 'queued') {
                return;
            }

            $customer = Customer::query()
                ->where('business_id', $this->businessId)
                ->find($log->customer_id);

            // The customer may have said stop between the owner's tap and this
            // job running. Their instruction wins over the queued intent.
            if ($customer === null || $customer->reminder_opt_out_at !== null) {
                $this->finish($log, 'failed', errorCode: 'opted_out', errorMessage: 'Customer opted out before sending');

                return;
            }

            $business = Business::findOrFail($this->businessId);
            $locale = $log->locale ?: ($business->default_language ?? config('app.locale'));
            $amount = (string) $log->amount_at_send;

            $result = $sender->send(
                (string) $log->phone_e164,
                ReminderMessage::text($business->name, $amount, $locale),
                $locale,
                [$business->name, Inr::format($amount)],
            );

            if ($result->accepted) {
                $this->finish($log, 'sent', providerMessageId: $result->providerMessageId);

                return;
            }

            $this->finish($log, 'failed', errorCode: $result->errorCode, errorMessage: $result->errorMessage);

            // Only a transient failure is worth another attempt; a 4xx would
            // fail identically forever. Re-raise so the queue retries.
            if ($result->retryable) {
                throw new \RuntimeException('WhatsApp send failed transiently: '.$result->errorMessage);
            }
        });
    }

    /** A job that dies for any other reason must not leave the row looking in-flight. */
    public function failed(?Throwable $e): void
    {
        DB::transaction(function () use ($e) {
            TenantContext::switchTo($this->businessId);
            app()->bind('tenant.id', fn () => $this->businessId);

            $log = ReminderLog::query()
                ->where('business_id', $this->businessId)
                ->find($this->reminderLogId);

            if ($log !== null && $log->status === 'queued') {
                $this->finish($log, 'failed', errorCode: 'job_failed', errorMessage: (string) $e?->getMessage());
            }
        });
    }

    private function finish(
        ReminderLog $log,
        string $status,
        ?string $providerMessageId = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        $log->status = $status;
        $log->status_at = now();
        $log->provider_message_id = $providerMessageId ?? $log->provider_message_id;
        $log->error_code = $errorCode;
        $log->error_message = $errorMessage === null ? null : mb_substr($errorMessage, 0, 255);
        $log->save();
    }
}
