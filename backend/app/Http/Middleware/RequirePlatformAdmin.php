<?php
// app/Http/Middleware/RequirePlatformAdmin.php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates /api/v1/admin/* to platform admins. Sits over auth:api but carries NO
 * tenant context — the console is cross-tenant by design. The flag is checked
 * live against the DB, so a just-revoked admin is 403'd on the very next request
 * rather than riding an old token.
 */
class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = auth()->id();

        if ($id === null) {
            abort(401);
        }

        $user = User::find($id);

        if ($user === null || ! $user->is_platform_admin) {
            abort(403, 'Platform admin only.');
        }

        // An impersonation token belongs to a real admin, so the flag check above
        // passes — but it is scoped to one tenant and read-only by construction.
        // Letting it back into the console would hand it every console mutation
        // (suspend, verify payment) under the guise of a read-only session. The
        // admin's ordinary token is the only key to this surface.
        if (JWTAuth::parseToken()->getPayload()->get('imp') !== null) {
            abort(403, 'Impersonation tokens cannot access the platform console.');
        }

        return $next($request);
    }
}
