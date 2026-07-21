<?php
// app/Http/Middleware/EnsurePlatformAdmin.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the Blade platform console (/admin/*) to platform admins on the SESSION
 * guard — the server-rendered twin of RequirePlatformAdmin, which guards the JWT
 * /api/v1/admin/* surface.
 *
 * The flag is checked LIVE against the loaded user, so a just-revoked admin is
 * refused on their very next request rather than riding a stale session. Like the
 * API gate, this middleware carries NO tenant context: the console is cross-tenant
 * by design.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! $user->is_platform_admin) {
            // A signed-in but non-admin user is a 403, not a redirect: they are
            // authenticated, just not authorised, and bouncing them to /app would
            // hide that the console exists behind a confusing silent redirect.
            abort(403, 'Platform admin only.');
        }

        return $next($request);
    }
}
