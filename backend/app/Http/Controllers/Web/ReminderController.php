<?php
// app/Http/Controllers/Web/ReminderController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ReminderLog;
use App\Reminders\ReminderMessage;
use App\Services\KhataService;
use App\Services\ReminderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Payment reminders (Phase 4a): Blade, online-only, owner-only. Same owner-tool
 * pattern as ExpenseController/SupplierController — the caller's OWNED business
 * is resolved from their membership (never the request), and work runs
 * tenant-pinned (RLS + app scope + owner). Not behind the write plan-gate: a
 * lapsed owner may still chase their own money.
 *
 * Nothing here sends anything. `send` records that the owner asked to remind
 * someone and hands them a wa.me link; the message leaves the owner's own
 * WhatsApp. Phase 4b replaces that redirect with a Cloud API call.
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

        [$business, $rows] = $this->runInTenant($businessId, fn () => [
            Business::findOrFail($businessId),
            $reminders->overdue($businessId),
        ]);

        return view('reminders.index', [
            'businessId' => $businessId,
            'business' => $business,
            'rows' => $rows,
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

        $url = $this->runInTenant($businessId, function () use ($businessId, $customer, $khata) {
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
                return null;
            }

            $outstanding = $khata->outstandingFor($model);

            $log = new ReminderLog([
                'business_id' => $businessId,
                'customer_id' => $model->id,
                'channel' => 'wa_link',
                'amount_at_send' => $outstanding,
                'locale' => $locale,
                'phone_e164' => $e164,
            ]);
            $log->created_by = (int) app('tenant.user_id');
            $log->save();

            return ReminderMessage::url($model->phone, $business->name, $outstanding, $locale);
        });

        if ($url === null) {
            return redirect()->route('reminders', ['business' => $businessId])
                ->with('error', __('reminders.cannot_send'));
        }

        return redirect()->away($url);
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
