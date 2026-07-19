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
 * Reads run on the SELECT-only BYPASSRLS connection (pgsql_platform), so the
 * listing spans every tenant with no tenant GUC set — and cannot mutate. There
 * is no tenant scope here by design: the console is cross-tenant.
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

        $page = DB::connection('pgsql_platform')
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
}
