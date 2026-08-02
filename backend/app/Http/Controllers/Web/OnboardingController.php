<?php
// app/Http/Controllers/Web/OnboardingController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\Membership;
use App\Models\Product;
use App\Services\BusinessProvisioner;
use App\Services\CatalogTemplateService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The onboarding chain, Blade and online-only (docs/frontend-plan.md §7):
 * create business → choose template → invite staff → into the app.
 *
 * Runs on the web (session) guard, which carries a user but NO tenant. The
 * steps after business creation touch tenant-scoped tables (catalog, invites),
 * so this controller pins the tenant itself via TenantContext, the same pattern
 * the Artisan importer uses for non-HTTP tenant work — rather than routing
 * through the JWT tenant.context middleware, which this surface does not have.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly BusinessProvisioner $provisioner,
        private readonly CatalogTemplateService $templates,
    ) {}

    /* --- step 1: create the business ------------------------------- */

    public function business(): View|RedirectResponse
    {
        // Already onboarded? Do not offer a second "create your first shop".
        if ($this->ownedBusinessId() !== null) {
            return redirect()->route('onboarding.template');
        }

        return view('onboarding.business');
    }

    public function storeBusiness(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'gstin' => ['nullable', 'string', 'max:15'],
        ]);

        $this->provisioner->provision((int) auth()->id(), $data + ['default_language' => app()->getLocale()]);

        return redirect()->route('onboarding.template');
    }

    /* --- step 2: choose a catalog template ------------------------- */

    public function template(): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId();

        if ($businessId === null) {
            return redirect()->route('onboarding.business');
        }

        return view('onboarding.template', [
            'templates' => $this->templates->options(),
            // If a catalog already exists, seeding is closed (it is one-time),
            // so the view offers "continue" rather than a doomed second seed.
            'alreadySeeded' => $this->tenantHas($businessId, fn () => Product::query()->exists()),
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId();

        if ($businessId === null) {
            return redirect()->route('onboarding.business');
        }

        // 'blank' is a first-class option (CatalogTemplateService::BLANK) — a
        // shop with an unusual catalog builds it by hand. apply() no-ops on it,
        // so no special-casing is needed here.
        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(CatalogTemplateService::available())],
        ]);

        $this->tenantHas($businessId, function () use ($businessId, $data) {
            // Guard the one-time rule here too, so a double submit does not hit
            // the pack_sizes unique index as a raw 500.
            if (! Product::query()->exists()) {
                $this->templates->apply($data['template'], $businessId);
            }

            return null;
        });

        return redirect()->route('onboarding.invite');
    }

    /* --- step 3: invite staff (optional) --------------------------- */

    public function invite(): View|RedirectResponse
    {
        $businessId = $this->ownedBusinessId();

        if ($businessId === null) {
            return redirect()->route('onboarding.business');
        }

        return view('onboarding.invite', ['link' => session('invite_link')]);
    }

    public function storeInvite(Request $request): RedirectResponse
    {
        $businessId = $this->ownedBusinessId();

        if ($businessId === null) {
            return redirect()->route('onboarding.business');
        }

        $data = $request->validate([
            'role' => ['required', 'in:admin,salesman,accountant'],
        ]);

        $token = $this->tenantHas($businessId, function () use ($businessId, $data) {
            $invite = Invite::create([
                'business_id' => $businessId,
                'role' => $data['role'],
                'token' => Str::random(48),
                'invited_by' => auth()->id(),
                'expires_at' => Carbon::now()->addDays(7),
            ]);

            return $invite->token;
        });

        // Flash the shareable link back to the same page; the owner sends it via
        // WhatsApp and can generate more or finish.
        return redirect()->route('onboarding.invite')
            ->with('invite_link', url("/invite/accept?token={$token}"));
    }

    /* --- helpers --------------------------------------------------- */

    /**
     * The business this user owns, read under their own user context.
     * Memberships are tenant-scoped, so this runs inside TenantContext::forUser,
     * which suspends the scope for the pre-tenant-selection window.
     */
    private function ownedBusinessId(): ?string
    {
        return TenantContext::forUser(
            (int) auth()->id(),
            fn () => Membership::where('user_id', auth()->id())
                ->where('role', 'owner')
                ->value('business_id')
        );
    }

    /**
     * Run a callback with the tenant bound — the binding BelongsToTenant reads
     * — inside one transaction. Mirrors the importer's runInTenant.
     *
     * @template T
     * @param  callable(): T  $work
     * @return T
     */
    private function tenantHas(string $businessId, callable $work): mixed
    {
        return DB::transaction(function () use ($businessId, $work) {
            TenantContext::switchTo($businessId);
            app()->bind('tenant.id', fn () => $businessId);
            app()->bind('tenant.user_id', fn () => (int) auth()->id());

            return $work();
        });
    }
}
