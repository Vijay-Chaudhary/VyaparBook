<?php

namespace App\Http\Middleware;

use App\Models\Membership;
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

        // bind(), not instance(): the container resolves instances via
        // isset($this->instances[$abstract]), and isset(null) is false — a null
        // instance falls through to construction and throws
        // "Target class [tenant.id] does not exist".
        app()->bind('tenant.id', fn () => null);
        app()->bind('tenant.role', fn () => null);
        app()->bind('tenant.user_id', fn () => $userId);

        DB::beginTransaction();

        try {
            // set_config(..., true) is SET LOCAL with bind parameters — Postgres
            // rejects placeholders in a bare `SET LOCAL`.
            DB::statement("select set_config('app.current_user_id', ?, true)", [(string) $userId]);

            if ($tid !== null) {
                $isMember = Membership::where('user_id', $userId)
                    ->where('business_id', $tid)
                    ->exists();

                if (! $isMember) {
                    DB::rollBack();

                    return response()->json(['message' => 'Not a member of this business.'], 403);
                }

                DB::statement("select set_config('app.current_tenant', ?, true)", [(string) $tid]);
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
