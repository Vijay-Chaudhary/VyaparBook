<?php
// app/Http/Controllers/Web/Admin/ConsoleController.php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The Blade platform (Superadmin) console — server-rendered twin of the JWT
 * /api/v1/admin/* surface (docs/frontend-plan.md §7 Phase 7).
 *
 * Reads run on the SELECT-only BYPASSRLS connection (pgsql_platform), exactly as
 * the API's Admin\TenantController does: the listing spans every tenant with no
 * tenant GUC set, and the connection physically cannot mutate. The console is
 * cross-tenant by design; there is no tenant scope here.
 *
 * Session-gated to platform admins by the platform_admin.web middleware.
 */
class ConsoleController extends Controller
{
    /** Paginated tenant directory with current billing state; ?q= name search. */
    public function index(Request $request): View
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $q = $data['q'] ?? null;

        $tenants = DB::connection('pgsql_platform')
            ->table('businesses as b')
            ->leftJoin('subscriptions as s', 's.business_id', '=', 'b.id')
            ->select([
                'b.id',
                'b.name',
                'b.city',
                'b.plan',
                'b.created_at',
                's.status as subscription_status',
                's.plan as subscription_plan',
                's.trial_ends_at',
                's.current_period_end',
            ])
            ->when($q !== null, fn ($query) => $query->where('b.name', 'ilike', '%'.$q.'%'))
            ->orderByDesc('b.created_at')
            ->paginate(25)
            ->withQueryString(); // keep ?q= across page links

        return view('admin.console.index', [
            'tenants' => $tenants,
            'q' => $q,
        ]);
    }

    /** Single-tenant drill-down: business, billing, members, recent payments. */
    public function show(string $id): View
    {
        $platform = DB::connection('pgsql_platform');

        $business = $platform->table('businesses')
            ->where('id', $id)
            ->first(['id', 'name', 'city', 'gstin', 'default_language', 'plan', 'created_at']);

        abort_if($business === null, 404, 'Tenant not found.');

        $subscription = $platform->table('subscriptions')
            ->where('business_id', $id)
            ->first(['status', 'plan', 'trial_ends_at', 'current_period_end']);

        $members = $platform->table('memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.business_id', $id)
            ->orderBy('u.name')
            ->get(['u.id as user_id', 'u.name', 'u.email', 'u.phone', 'm.role']);

        $payments = $platform->table('subscription_payments')
            ->where('business_id', $id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'uuid', 'plan', 'amount', 'gst_amount', 'mode', 'reference', 'period_months', 'status', 'verified_at', 'created_at']);

        // Roles actually held in this tenant — the impersonation control only
        // offers a role someone really has, mirroring the API's 422 guard.
        $roles = $members->pluck('role')->unique()->values();

        return view('admin.console.show', [
            'business' => $business,
            'subscription' => $subscription,
            'members' => $members,
            'payments' => $payments,
            'roles' => $roles,
            'impersonation' => session('impersonation'),
        ]);
    }
}
