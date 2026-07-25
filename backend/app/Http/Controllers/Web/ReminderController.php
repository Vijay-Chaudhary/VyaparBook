<?php
// app/Http/Controllers/Web/ReminderController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ReminderBatch;
use App\Models\ReminderLog;
use App\Jobs\SendReminderJob;
use App\Reminders\ReminderMessage;
use App\Services\KhataService;
use App\Services\ReminderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Payment reminders (Phase 4a/4b): Blade, online-only, owner-only. Same owner-tool
 * pattern as ExpenseController/SupplierController — the caller's OWNED business
 * is resolved from their membership (never the request), and work runs
 * tenant-pinned (RLS + app scope + owner). Not behind the write plan-gate: a
 * lapsed owner may still chase their own money.
 *
 * `send` always records the owner's intent first, then routes it by the
 * configured transport (Phase 4b):
 *
 *   - driver 'log' (the default): hands back a wa.me link, so the message
 *     leaves the OWNER's own WhatsApp — Phase 4a's behaviour, unchanged.
 *   - driver 'cloud_api': queues SendReminderJob, so the message leaves the
 *     PLATFORM's number and the owner stays in the app.
 *
 * The opt-out and phone checks guard both paths: the UI hides the button, but
 * the UI is not a security boundary, and an opted-out customer must not be
 * reachable by replaying a URL.
 */
class ReminderController extends Controller
{
    use ResolvesOwnedTenant;

    /** The overdue review list. */
    public function index(Request $request, ReminderService $reminders): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        [$business, $rows, $planned] = $this->runInTenant($businessId, fn () => [
            Business::findOrFail($businessId),
            $reminders->overdue($businessId),
            // Phase 4c: what the scheduler intends to send, so the owner can
            // stop it before it goes rather than read about it afterwards.
            ReminderLog::query()
                ->where('business_id', $businessId)
                ->whereNotNull('batch_id')
                ->where('status', 'planned')
                ->with('customer')
                ->get(),
        ]);

        return view('reminders.index', [
            'businessId' => $businessId,
            'business' => $business,
            'rows' => $rows,
            'planned' => $planned,
            'minOutstanding' => (string) $business->reminder_min_outstanding,
            'minDays' => (int) $business->reminder_min_days,
        ]);
    }

    /**
     * Log the intent, then hand the owner the prefilled WhatsApp link.
     *
     * The UI hides the button for a customer who cannot be messaged, but the
     * check is repeated here: the UI is not a security boundary, and an
     * opted-out customer must not be reachable by replaying a URL.
     */
    public function send(Request $request, string $customer, KhataService $khata): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $viaCloudApi = config('services.whatsapp.driver') === 'cloud_api';

        $result = $this->runInTenant($businessId, function () use ($businessId, $customer, $khata, $viaCloudApi) {
            // Resolved inside the pin, never via implicit binding — no tenant is
            // pinned during route resolution, so binding would bypass RLS.
            $model = Customer::query()
                ->where('business_id', $businessId)
                ->whereNull('archived_at')
                ->find($customer);

            if ($model === null) {
                throw new NotFoundHttpException;
            }

            $business = Business::findOrFail($businessId);
            $locale = $business->default_language ?? config('app.locale');
            $e164 = ReminderMessage::normalisePhone($model->phone);

            if ($model->reminder_opt_out_at !== null || $e164 === null) {
                return ['outcome' => 'blocked'];
            }

            $outstanding = $khata->outstandingFor($model);

            $log = new ReminderLog([
                'business_id' => $businessId,
                'customer_id' => $model->id,
                'channel' => $viaCloudApi ? 'cloud_api' : 'wa_link',
                'amount_at_send' => $outstanding,
                'locale' => $locale,
                'phone_e164' => $e164,
            ]);
            // Phase 4a's deep link is as "sent" as that channel can report; the
            // Cloud API row stays queued until the worker hears back from Meta.
            $log->status = $viaCloudApi ? 'queued' : 'sent';
            $log->status_at = $viaCloudApi ? null : now();
            $log->created_by = (int) app('tenant.user_id');
            $log->save();

            if ($viaCloudApi) {
                // Row first, dispatch second: a reminder must never be invisible
                // just because a worker is behind.
                SendReminderJob::dispatch($log->id, $businessId);

                return ['outcome' => 'queued'];
            }

            return [
                'outcome' => 'link',
                'url' => ReminderMessage::url($model->phone, $business->name, $outstanding, $locale),
            ];
        });

        return match ($result['outcome']) {
            // Queued for the platform number — the owner stays in the app.
            'queued' => redirect()->route('reminders', ['business' => $businessId])
                ->with('status', __('reminders.queued')),
            // Opted out or an unusable number: say so rather than pretending.
            'blocked' => redirect()->route('reminders', ['business' => $businessId])
                ->with('error', __('reminders.cannot_send')),
            // Phase 4a: hand off to the owner's own WhatsApp.
            default => redirect()->away($result['url']),
        };
    }

    /**
     * Save the automation settings (Phase 4c).
     *
     * send_at is validated against the global quiet-hours window: a tenant may
     * choose WHEN inside it, never that their customers be messaged at 3am.
     */
    public function settings(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'reminder_auto_enabled' => ['nullable', 'boolean'],
            'reminder_send_at' => ['required', 'date_format:H:i', 'after_or_equal:09:00', 'before_or_equal:19:59'],
            'reminder_cooldown_days' => ['required', 'integer', 'min:0', 'max:90'],
            'reminder_daily_cap' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        $this->runInTenant($businessId, function () use ($businessId, $data, $request) {
            $business = Business::findOrFail($businessId);
            $business->reminder_auto_enabled = $request->boolean('reminder_auto_enabled');
            $business->reminder_send_at = $data['reminder_send_at'];
            $business->reminder_cooldown_days = (int) $data['reminder_cooldown_days'];
            $business->reminder_daily_cap = (int) $data['reminder_daily_cap'];
            $business->save();
        });

        return redirect()->route('reminders', ['business' => $businessId])
            ->with('status', __('reminders.settings_saved'));
    }

    /** Cancel one planned reminder before the scheduler releases it. */
    public function cancelPlanned(Request $request, string $reminder): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $this->runInTenant($businessId, function () use ($businessId, $reminder) {
            $log = ReminderLog::query()
                ->where('business_id', $businessId)
                ->where('status', 'planned')
                ->find($reminder);

            if ($log === null) {
                throw new NotFoundHttpException;
            }

            // Cancelled, not deleted: the owner's decision not to chase someone
            // is history worth keeping.
            $log->status = 'cancelled';
            $log->status_at = Carbon::now();
            $log->save();
        });

        return redirect()->route('reminders', ['business' => $businessId])
            ->with('status', __('reminders.cancelled'));
    }

    /** Customer asked not to be reminded (DPDP — see the migration's note). */
    public function optOut(Request $request, string $customer): RedirectResponse
    {
        return $this->setOptOut($request, $customer, Carbon::now(), 'reminders.opted_out');
    }

    /** Customer allowed reminders again. */
    public function optIn(Request $request, string $customer): RedirectResponse
    {
        return $this->setOptOut($request, $customer, null, 'reminders.opted_in');
    }

    private function setOptOut(Request $request, string $customer, ?Carbon $at, string $messageKey): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $name = $this->runInTenant($businessId, function () use ($businessId, $customer, $at) {
            $model = Customer::query()
                ->where('business_id', $businessId)
                ->whereNull('archived_at')
                ->find($customer);

            if ($model === null) {
                throw new NotFoundHttpException;
            }

            $model->reminder_opt_out_at = $at;
            $model->save();

            return $model->name;
        });

        return redirect()->route('reminders', ['business' => $businessId])
            ->with('status', __($messageKey, ['name' => $name]));
    }
}
