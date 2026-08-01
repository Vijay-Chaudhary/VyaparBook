<?php
// app/Http/Controllers/Api/V1/Admin/TenantController.php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform (Superadmin) console: cross-tenant tenant directory.
 *
 * Reads run on the SELECT-only connection (mysql_platform), so the listing spans
 * every tenant and cannot mutate — its user is granted SELECT and nothing else.
 * There is no tenant scope here by design: the console is cross-tenant.
 */
class TenantController extends Controller
{
    /**
     * Paginated directory of businesses with their current billing state.
     * Optional case-insensitive name search via ?q=.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = $data['q'] ?? null;
        $perPage = $data['per_page'] ?? 25;

        $page = DB::connection('mysql_platform')
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
            ->when($q !== null, fn ($query) => $query->where('b.name', 'like', '%'.$q.'%'))
            ->orderByDesc('b.created_at')
            ->paginate($perPage);

        $page->through(fn ($row) => [
            'id' => $row->id,
            'name' => $row->name,
            'city' => $row->city,
            'plan' => $row->plan,
            'created_at' => $row->created_at,
            'subscription' => $row->subscription_status === null ? null : [
                'status' => $row->subscription_status,
                'plan' => $row->subscription_plan,
                'trial_ends_at' => $row->trial_ends_at,
                'current_period_end' => $row->current_period_end,
            ],
        ]);

        // Clean {data, meta} envelope rather than the paginator's flat top-level
        // fields — this is the first paginated surface, so it sets the shape.
        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Single-tenant drill-down: the business, its billing state, its members
     * and recent subscription payments. All reads on the BYPASSRLS connection.
     */
    public function show(string $id): JsonResponse
    {
        $platform = DB::connection('mysql_platform');

        $business = $platform->table('businesses')
            ->where('id', $id)
            ->first(['id', 'name', 'city', 'gstin', 'default_language', 'plan', 'created_at']);

        if ($business === null) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

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

        return response()->json([
            'id' => $business->id,
            'name' => $business->name,
            'city' => $business->city,
            'gstin' => $business->gstin,
            'default_language' => $business->default_language,
            'plan' => $business->plan,
            'created_at' => $business->created_at,
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->status,
                'plan' => $subscription->plan,
                'trial_ends_at' => $subscription->trial_ends_at,
                'current_period_end' => $subscription->current_period_end,
            ],
            'members' => $members,
            'payments' => $payments,
        ]);
    }
}
