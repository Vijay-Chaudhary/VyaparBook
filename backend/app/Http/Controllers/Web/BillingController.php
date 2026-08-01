<?php
// app/Http/Controllers/Web/BillingController.php

namespace App\Http\Controllers\Web;

use App\Billing\PlanCatalog;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\EntitlementService;
use App\Services\SubscriptionService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Billing & plan, Blade and online-only (docs/frontend-plan.md §7 Phase 6):
 * the owner sees their plan, usage against limits, and the dunning state, and
 * records a manual/UPI payment to upgrade or recover from read_only.
 *
 * Owner-only, exactly like the JWT BillingController (PRD §7): a page is only
 * rendered for a business the caller genuinely OWNS, resolved from their own
 * membership — never trusted from the request. A non-owner, or an owner asking
 * for a business they do not own, is sent back to the app rather than shown a
 * 403 they can do nothing about.
 *
 * Runs on the web (session) guard, which carries a user but NO tenant, so this
 * controller pins the tenant itself via TenantContext — the same pattern the
 * onboarding flow and the Artisan importer use for tenant work with no JWT
 * middleware in front of it.
 *
 * Deliberately OUTSIDE any plan gate: an owner in read_only (dunning) is exactly
 * the person who must reach this page and pay. It never blocks — it recovers.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlement,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /** Plan, live usage vs limits, dunning state and payment history. */
    public function show(Request $request): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->query('business'));

        if ($businessId === null) {
            return redirect()->route('app');
        }

        $view = $this->runInTenant($businessId, function () use ($businessId) {
            // syncStatus may flip an expired trial/period to past_due before we
            // read it, so the page never shows a stale "trialing" after expiry.
            $sub = $this->subscriptions->syncStatus(Subscription::firstOrFail());
            $plan = $this->entitlement->effectivePlan($sub);

            return [
                'businessId' => $businessId,
                'plan' => $plan,
                'status' => $sub->status,
                'trialEndsAt' => $sub->trial_ends_at,
                'trialDaysLeft' => $this->entitlement->trialDaysLeft($sub),
                'currentPeriodEnd' => $sub->current_period_end,
                'limits' => PlanCatalog::limits($plan),
                'usage' => $this->entitlement->usage($sub),
                'overLimit' => [
                    'customers' => $this->entitlement->isOverLimit($sub, 'customers'),
                    'users' => $this->entitlement->isOverLimit($sub, 'users'),
                ],
                'mayWrite' => $this->entitlement->mayWrite($sub),
                'payments' => SubscriptionPayment::orderByDesc('created_at')->get(),
            ];
        });

        return view('billing.show', $view);
    }

    /**
     * Record a manual/UPI payment as PENDING — no activation here. The platform
     * (Superadmin) verifies it later, which is what actually flips the plan to
     * active. Idempotent by uuid, mirroring the JWT recordPayment: a double
     * submit replays the same row instead of double-charging the ledger.
     */
    public function storePayment(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId($request->input('business'));

        if ($businessId === null) {
            return redirect()->route('app');
        }

        $data = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'plan' => ['required', Rule::in(['pro'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(['upi', 'bank', 'manual'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'period_months' => ['required', 'integer', 'min:1', 'max:24'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $uuid = $data['uuid'] ?? (string) Str::uuid();

        $this->runInTenant($businessId, function () use ($data, $uuid) {
            if (SubscriptionPayment::where('uuid', $uuid)->exists()) {
                return; // idempotent replay — do not append a second row
            }

            // GST is 18% of the amount, at scale 2 with bcmath (never floats),
            // exactly as the JWT path computes it.
            SubscriptionPayment::create([
                'uuid' => $uuid,
                'plan' => $data['plan'],
                'amount' => $data['amount'],
                'gst_amount' => bcmul((string) $data['amount'], '0.18', 2),
                'mode' => $data['mode'],
                'reference' => $data['reference'] ?? null,
                'period_months' => $data['period_months'],
                'status' => 'pending',
                'note' => $data['note'] ?? null,
            ]);
        });

        return redirect()
            ->route('billing', ['business' => $businessId])
            ->with('billing_status', 'payment_recorded');
    }

    /* --- helpers --------------------------------------------------- */

    /**
     * The id of a business this user OWNS. An explicit ?business=… scopes to that
     * one — but only if the caller owns it, so a guessed id cannot open someone
     * else's billing. With no preference, the sole owned business is used.
     * Returns null when the user owns nothing matching — the caller redirects.
     *
     * Read under the user's own context (memberships are tenant-scoped, and no
     * tenant is pinned yet), the same as onboarding's ownedBusinessId().
     */
    private function ownedBusinessId(?string $requested): ?string
    {
        return TenantContext::forUser(
            (int) auth()->id(),
            function () use ($requested) {
                $query = Membership::where('user_id', auth()->id())->where('role', 'owner');

                if ($requested !== null) {
                    $query->where('business_id', $requested);
                }

                return $query->value('business_id');
            }
        );
    }

    /**
     * Run $work with the tenant pinned — the RLS GUC and the app-level scope,
     * plus the owner role for BillingPolicy parity — inside one transaction.
     * Mirrors OnboardingController::tenantHas and the importer's runInTenant.
     *
     * @template T
     * @param  callable(): T  $work
     * @return T
     */
    private function runInTenant(string $businessId, callable $work): mixed
    {
        return DB::transaction(function () use ($businessId, $work) {
            TenantContext::switchTo($businessId);
            app()->bind('tenant.id', fn () => $businessId);
            app()->bind('tenant.user_id', fn () => (int) auth()->id());
            // Resolved from the owner membership above, never the request; lets
            // any BillingPolicy::manage() check inside $work agree with the JWT path.
            app()->bind('tenant.role', fn () => 'owner');

            return $work();
        });
    }
}
