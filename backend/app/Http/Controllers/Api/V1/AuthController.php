<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\TokenService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly ConsentService $consents,
    ) {}

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            // DPDP consent (PRD §13): 'accepted' means the caller sent a literal
            // true — a missing or false field fails. Consent must be a clear
            // affirmative action, so it cannot be defaulted on the server.
            'consent' => ['required', 'accepted'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $this->consents->grant($user, $request);

        return response()->json([
            'token' => $this->tokenService->issue($user),
            'policy_version' => $this->consents->currentPolicyVersion(),
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $membership = TenantContext::forUser(
            $user->id,
            fn () => $user->memberships()->count() === 1 ? $user->memberships()->first() : null
        );

        return response()->json(['token' => $this->tokenService->issue($user, $membership)]);
    }
}
