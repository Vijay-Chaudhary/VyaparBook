<?php
// app/Http/Controllers/Web/BeatController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\ResolvesOwnedTenant;
use App\Http\Controllers\Controller;
use App\Models\Beat;
use App\Models\BeatCustomer;
use App\Models\Customer;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Beat planning (PRD Phase 3): Blade, online-only, owner-only — the same
 * owner-tool pattern as expenses and reminders.
 *
 * Planning happens here; the salesman's phone only READS the result, through
 * the existing delta pull. That asymmetry is the point: no push path means no
 * conflict rules to get wrong in the field.
 */
class BeatController extends Controller
{
    use ResolvesOwnedTenant;

    public function index(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        [$beats, $customers, $staff] = $this->runInTenant($businessId, fn () => [
            Beat::query()->whereNull('archived_at')->with('beatCustomers.customer')->orderBy('name')->get(),
            Customer::query()->whereNull('archived_at')->orderBy('name')->get(),
            // Anyone in the business can be assigned a beat; the salesman role is
            // the usual case but a working owner is common in a small shop.
            Membership::query()->where('business_id', $businessId)->with('user')->get(),
        ]);

        return view('beats.index', [
            'businessId' => $businessId,
            'beats' => $beats,
            'customers' => $customers,
            'staff' => $staff,
            'today' => Carbon::today()->isoWeekday(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'min:1', 'max:7'],
            'assigned_user_id' => ['nullable', 'integer'],
        ]);

        $this->runInTenant($businessId, function () use ($businessId, $data) {
            // An unowned user id must not be assignable: membership is checked
            // rather than trusted from the form.
            $assignee = $data['assigned_user_id'] ?? null;

            if ($assignee !== null && ! Membership::query()
                ->where('business_id', $businessId)->where('user_id', $assignee)->exists()) {
                $assignee = null;
            }

            Beat::create([
                'business_id' => $businessId,
                'name' => $data['name'],
                'weekdays' => array_values(array_unique(array_map('intval', $data['weekdays']))),
                'assigned_user_id' => $assignee,
            ]);
        });

        return redirect()->route('beats', ['business' => $businessId])->with('status', __('beats.saved'));
    }

    /** Replace a beat's customer list, in the given call order. */
    public function updateCustomers(Request $request, string $beat): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'customers' => ['nullable', 'array'],
            'customers.*' => ['uuid'],
        ]);

        $this->runInTenant($businessId, function () use ($businessId, $beat, $data) {
            $model = Beat::query()->where('business_id', $businessId)->find($beat);

            if ($model === null) {
                throw new NotFoundHttpException;
            }

            // Rebuilt rather than diffed: the list is short, and a rebuild
            // cannot leave a stale row pointing at a customer no longer on it.
            BeatCustomer::query()->where('beat_id', $model->id)->delete();

            foreach (array_values($data['customers'] ?? []) as $position => $customerId) {
                if (! Customer::query()->where('business_id', $businessId)->whereKey($customerId)->exists()) {
                    continue;
                }

                BeatCustomer::create([
                    'business_id' => $businessId,
                    'beat_id' => $model->id,
                    'customer_id' => $customerId,
                    'position' => $position + 1,
                ]);
            }
        });

        return redirect()->route('beats', ['business' => $businessId])->with('status', __('beats.saved'));
    }

    public function archive(Request $request, string $beat): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));
        if ($businessId === null) {
            return redirect()->route('app');
        }

        $this->runInTenant($businessId, function () use ($businessId, $beat) {
            $model = Beat::query()->where('business_id', $businessId)->find($beat);

            if ($model === null) {
                throw new NotFoundHttpException;
            }

            // Archived, not deleted: the beat still stream-syncs so devices
            // holding it learn to drop it, as archived customers already do.
            $model->archived_at = Carbon::now();
            $model->save();
        });

        return redirect()->route('beats', ['business' => $businessId])->with('status', __('beats.archived'));
    }
}
