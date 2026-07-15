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
}
