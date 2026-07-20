<?php
// app/Services/ConsentService.php

namespace App\Services;

use App\Models\Consent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Records and reads DPDP consent (PRD §13).
 *
 * Consent is a ledger of events, so "does this user consent?" is always derived
 * from the latest one — never from a stored flag that could drift from the
 * evidence.
 */
class ConsentService
{
    public function currentPolicyVersion(): string
    {
        return (string) config('dpdp.policy_version');
    }

    /**
     * Record an affirmative grant, capturing the request as evidence of the
     * action (IP and user agent), against the policy version live right now.
     */
    public function grant(User $user, ?Request $request = null): Consent
    {
        return $this->record($user, Consent::GRANTED, $request);
    }

    /**
     * Record a withdrawal. Deliberately does NOT delete the user's data: DPDP
     * requires that withdrawal be honoured, but erasure of a shop's books is an
     * irreversible operator action (tenant:export then tenant:erase), not a side
     * effect of a checkbox. This marks the account as awaiting that offboarding.
     */
    public function withdraw(User $user, ?Request $request = null): Consent
    {
        return $this->record($user, Consent::WITHDRAWN, $request);
    }

    /** The latest consent event, or null if the user never acted. */
    public function latest(User $user): ?Consent
    {
        return Consent::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * True only when the newest event is a grant AND it names the policy version
     * in force. Consent to a superseded notice is not consent to the current
     * one, so a policy bump makes everyone stale rather than silently consenting.
     */
    public function hasCurrentConsent(User $user): bool
    {
        $latest = $this->latest($user);

        return $latest !== null
            && $latest->action === Consent::GRANTED
            && $latest->policy_version === $this->currentPolicyVersion();
    }

    private function record(User $user, string $action, ?Request $request): Consent
    {
        return Consent::create([
            'user_id' => $user->id,
            'action' => $action,
            'policy_version' => $this->currentPolicyVersion(),
            'ip_address' => $request?->ip(),
            // Truncated to the column width: evidence, not a full header dump.
            'user_agent' => $request === null ? null : substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
