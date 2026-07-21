<?php
// app/Http/Controllers/Web/RegisterController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Blade signup (docs/frontend-plan.md §7 Phase 4).
 *
 * Mirrors the API's AuthController::register, including the mandatory DPDP
 * consent — a signup without an affirmative consent must fail on both surfaces,
 * or the web door quietly becomes the way to create an account without it.
 *
 * On success the user is logged into the SESSION (not handed a JWT): the Blade
 * onboarding flow that follows is session-authorised, and the React layer mints
 * its own token from that session later.
 */
class RegisterController extends Controller
{
    public function __construct(private readonly ConsentService $consents) {}

    public function show(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // 'accepted' requires a literal true; consent can never be defaulted.
            'consent' => ['required', 'accepted'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $this->consents->grant($user, $request);

        Auth::login($user);
        $request->session()->regenerate();

        // A brand-new user has no business yet, so onboarding starts at
        // "create your shop", not the app.
        return redirect()->route('onboarding.business');
    }
}
