<?php
// app/Http/Controllers/Api/V1/Admin/ImpersonationController.php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\PlatformAudit;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform (Superadmin) console: impersonate-for-support (PRD §12).
 *
 * Mints a short-lived READ-ONLY token that renders a tenant exactly as one of its
 * own roles sees it, so an operator can reproduce "my khata looks wrong" without
 * asking for the owner's phone or credentials. The token can never write — see
 * SetTenantContext, which rejects unsafe methods on it and re-checks the admin
 * flag live on every request.
 *
 * Every mint is audited. There is no matching "stop" endpoint on purpose: the
 * token is bearer-only and short-TTL, so ending a session means discarding it,
 * and a revoke endpoint would imply a server-side session that does not exist.
 */
class ImpersonationController extends Controller
{
    /** Support window. Short enough that a forgotten tab expires on its own. */
    private const TTL_MINUTES = 30;

    public function __construct(private readonly TokenService $tokens) {}

    /** POST /admin/tenants/{id}/impersonate */
    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'role' => ['nullable', 'string', 'in:owner,admin,salesman,accountant'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $platform = DB::connection('mysql_platform');

        $business = $platform->table('businesses')->where('id', $id)->first(['id', 'name']);

        if ($business === null) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        // Default to the role with the widest view, so support sees everything the
        // complaint could be about. A narrower role can be requested to reproduce a
        // salesman's or accountant's view specifically.
        $role = $data['role'] ?? 'owner';

        // The role must actually exist in this tenant: impersonating a role nobody
        // holds would show a view no real user of this business ever sees, which is
        // a misleading basis for support — and, for 'owner', usually means the
        // tenant is mid-onboarding rather than that the operator picked wrong.
        $roleExists = $platform->table('memberships')
            ->where('business_id', $id)
            ->where('role', $role)
            ->exists();

        if (! $roleExists) {
            return response()->json([
                'message' => "This tenant has no member with the '{$role}' role.",
            ], 422);
        }

        $admin = User::findOrFail(auth()->id());

        $token = $this->tokens->issueImpersonation($admin, $id, $role, self::TTL_MINUTES);

        PlatformAudit::record('impersonate_tenant', $id, [
            'role' => $role,
            'reason' => $data['reason'] ?? null,
            'ttl_minutes' => self::TTL_MINUTES,
        ]);

        return response()->json([
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in_minutes' => self::TTL_MINUTES,
            'read_only' => true,
            'tenant' => ['id' => $business->id, 'name' => $business->name],
            'role' => $role,
        ]);
    }
}
