<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class TokenService
{
    /**
     * Issue a JWT for a user, optionally scoped to an active business membership.
     * When no membership is given, the token carries no tid/role — the client
     * must resolve one via /businesses/mine + /businesses/{id}/switch.
     */
    public function issue(User $user, ?Membership $activeMembership = null): string
    {
        $claims = [
            'tid' => $activeMembership?->business_id,
            'role' => $activeMembership?->role,
        ];

        return JWTAuth::claims($claims)->fromUser($user);
    }

    /**
     * Issue a short-lived, READ-ONLY token that lets a platform admin see a tenant
     * exactly as the given role does (support impersonation, PRD §12).
     *
     * The subject stays the admin — we never mint a token as the tenant's user, so
     * `sub` in the audit trail and in any downstream log is always the real human.
     * `imp` marks the token as impersonation (SetTenantContext accepts it in place
     * of a Membership row, re-checking the admin flag live); `ro` pins it to reads.
     *
     * TTL is deliberately short and independent of JWT_TTL: a support window should
     * expire on its own, so a forgotten tab cannot keep a tenant's books open.
     */
    public function issueImpersonation(User $admin, string $businessId, string $role, int $ttlMinutes = 30): string
    {
        $claims = [
            'tid' => $businessId,
            'role' => $role,
            'imp' => $admin->id,
            'ro' => true,
        ];

        // TTL lives on the shared claim factory, not the token builder, so it must
        // be set around the mint and restored — otherwise every ordinary token
        // issued later in this process inherits the 30-minute support window.
        $factory = JWTAuth::factory();
        $previousTtl = $factory->getTTL();

        try {
            $factory->setTTL($ttlMinutes);

            return JWTAuth::claims($claims)->fromUser($admin);
        } finally {
            $factory->setTTL($previousTtl);
        }
    }
}
