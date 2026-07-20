<?php
// app/Http/Controllers/Api/V1/ConsentController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The data principal's own view of their consent (PRD §13, DPDP).
 *
 * Sits outside tenant context: consent belongs to the person, not to any one of
 * the businesses they are a member of.
 */
class ConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consents) {}

    /** GET /consent — current state plus the full history of events. */
    public function show(): JsonResponse
    {
        $user = User::findOrFail(auth()->id());
        $latest = $this->consents->latest($user);

        return response()->json([
            'consented' => $this->consents->hasCurrentConsent($user),
            'current_policy_version' => $this->consents->currentPolicyVersion(),
            'latest' => $latest === null ? null : [
                'action' => $latest->action,
                'policy_version' => $latest->policy_version,
                'recorded_at' => $latest->created_at,
            ],
            // The person can see the evidence held about them, not just the verdict.
            'history' => Consent::where('user_id', $user->id)
                ->orderBy('created_at')
                ->get(['action', 'policy_version', 'created_at']),
        ]);
    }

    /**
     * POST /consent/withdraw — DPDP requires withdrawal be as easy as granting,
     * so this needs nothing but the caller's own token.
     *
     * Withdrawal does not itself delete anything: erasing a shop's books is an
     * irreversible operator action (tenant:export then tenant:erase). The
     * response says so plainly rather than implying the data is already gone.
     */
    public function withdraw(Request $request): JsonResponse
    {
        $user = User::findOrFail(auth()->id());

        if (! $this->consents->hasCurrentConsent($user)) {
            return response()->json([
                'message' => 'No active consent to withdraw.',
                'consented' => false,
            ], 409);
        }

        $consent = $this->consents->withdraw($user, $request);

        return response()->json([
            'consented' => false,
            'withdrawn_at' => $consent->created_at,
            'message' => 'Consent withdrawn. Your data is retained until an operator completes erasure.',
        ]);
    }
}
