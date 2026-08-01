<?php
// app/Http/Controllers/Api/V1/Admin/SubscriptionController.php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Platform\PlatformAudit;
use App\Platform\PlatformTenantContext;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform (Superadmin) console: the suspend/reactivate lever on a tenant's
 * subscription. Suspending drops the tenant into read_only (dunning) — domain
 * writes soft-block with a 402 while reads flow; reactivating lifts it.
 *
 * Both writes pin the target tenant (PlatformTenantContext) so RLS confines the
 * mutation, and both are recorded in the platform audit trail.
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** POST /admin/tenants/{id}/suspend — idempotent. */
    public function suspend(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ])['reason'] ?? null;

        return $this->transition($id, fn (Subscription $sub) => $this->subscriptions->suspend($sub), 'suspend_tenant', ['reason' => $reason]);
    }

    /** POST /admin/tenants/{id}/reactivate — idempotent. */
    public function reactivate(string $id): JsonResponse
    {
        return $this->transition($id, fn (Subscription $sub) => $this->subscriptions->reactivate($sub), 'reactivate_tenant', []);
    }

    /**
     * Shared shell: load the tenant's subscription pinned to that tenant, apply the
     * transition, and audit only a real status change (a no-op replay leaves no
     * second trail entry).
     *
     * @param  callable(Subscription): Subscription  $apply
     * @param  array<string, mixed>  $metadata
     */
    private function transition(string $id, callable $apply, string $action, array $metadata): JsonResponse
    {
        $adminId = (int) auth()->id();

        $result = PlatformTenantContext::actAs($id, $adminId, function () use ($id, $apply, $action, $metadata) {
            // Pinned to $id: a missing or cross-tenant tenant is invisible under the tenant scope.
            $sub = Subscription::where('business_id', $id)->first();

            if ($sub === null) {
                return ['code' => 404, 'message' => 'Tenant subscription not found.'];
            }

            $before = $sub->status;
            $sub = $apply($sub);

            if ($sub->status !== $before) {
                PlatformAudit::record($action, $id, $metadata + ['from' => $before, 'to' => $sub->status]);
            }

            return ['code' => 200, 'subscription' => $sub];
        });

        if ($result['code'] !== 200) {
            return response()->json(['message' => $result['message']], $result['code']);
        }

        return response()->json([
            'subscription' => [
                'status' => $result['subscription']->status,
                'plan' => $result['subscription']->plan,
                'current_period_end' => $result['subscription']->current_period_end,
            ],
        ]);
    }
}
