<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $payload = JWTAuth::parseToken()->getPayload();
        $userId = (int) $payload->get('sub');
        $tid = $payload->get('tid');
        $role = $payload->get('role');
        $impersonator = $payload->get('imp');

        // Support impersonation (PRD §12): a platform admin holds a tenant-scoped
        // token but no Membership row, so the membership check below would 403 it.
        // Two conditions replace that check, both fail-closed:
        //   1. the admin flag is re-read from the DB on EVERY request, so revoking
        //      is_platform_admin kills live impersonation sessions immediately
        //      rather than letting an issued token ride to its expiry;
        //   2. the token is read-only — any unsafe method is rejected before it
        //      can touch the tenant's data.
        if ($impersonator !== null) {
            if (! $request->isMethodSafe()) {
                return response()->json([
                    'message' => 'Impersonation is read-only.',
                    'code' => 'impersonation_read_only',
                ], 403);
            }

            if (! User::where('id', $impersonator)->value('is_platform_admin')) {
                return response()->json(['message' => 'Platform admin only.'], 403);
            }
        }

        // bind(), not instance(): the container resolves instances via
        // isset($this->instances[$abstract]), and isset(null) is false — a null
        // instance falls through to construction and throws
        // "Target class [tenant.id] does not exist".
        app()->bind('tenant.id', fn () => null);
        app()->bind('tenant.role', fn () => null);
        app()->bind('tenant.user_id', fn () => $userId);

        // Under Postgres this transaction also scoped the tenant GUC that RLS
        // policies read. MySQL has neither, and the tenant now lives only in the
        // container bindings above — but the transaction stays, because it still
        // gives each request atomicity.
        DB::beginTransaction();

        try {
            if ($tid !== null) {
                // An impersonation token is authorised by the admin-flag check
                // above instead — its holder is deliberately not a member.
                $isMember = $impersonator !== null || Membership::where('user_id', $userId)
                    ->where('business_id', $tid)
                    ->exists();

                if (! $isMember) {
                    DB::rollBack();

                    return response()->json(['message' => 'Not a member of this business.'], 403);
                }

                app()->bind('tenant.id', fn () => $tid);
                app()->bind('tenant.role', fn () => $role);
            }

            $response = $next($request);
            DB::commit();

            return $response;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
