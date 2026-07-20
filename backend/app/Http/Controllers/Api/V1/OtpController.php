<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\OtpService;
use App\Services\TokenService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class OtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly TokenService $tokenService,
        private readonly ConsentService $consents,
    ) {}

    public function request(Request $request)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'regex:/^[0-9]{10,15}$/']]);

        $key = 'otp-request:' . $data['phone'];
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'Too many OTP requests. Try again later.'], 429);
        }
        RateLimiter::hit($key, 3600);

        $code = $this->otpService->generate($data['phone']);
        Log::info("OTP for {$data['phone']}: {$code}");

        $response = ['message' => 'OTP sent.'];
        if (app()->environment(['local', 'testing'])) {
            $response['debug_code'] = $code;
        }

        return response()->json($response);
    }

    public function verify(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        // This endpoint is both login and signup. Resolve which BEFORE consuming
        // the code: a new user must give DPDP consent (PRD §13), and failing that
        // validation after burning their OTP would force them to request another.
        $user = User::where('phone', $data['phone'])->first();
        $isSignup = $user === null;

        if ($isSignup) {
            // 'accepted' requires a literal true — consent cannot be defaulted
            // server-side. Returning users are not re-prompted.
            $request->validate(['consent' => ['required', 'accepted']]);
        }

        if (! $this->otpService->verify($data['phone'], $data['code'])) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        if ($isSignup) {
            $user = User::create([
                'phone' => $data['phone'],
                'name' => $data['phone'],
                'password' => bcrypt(str()->random(32)),
            ]);

            $this->consents->grant($user, $request);
        }

        $membership = TenantContext::forUser(
            $user->id,
            fn () => $user->memberships()->count() === 1 ? $user->memberships()->first() : null
        );

        return response()->json(['token' => $this->tokenService->issue($user, $membership)]);
    }
}
