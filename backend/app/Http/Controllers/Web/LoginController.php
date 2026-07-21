<?php
// app/Http/Controllers/Web/LoginController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session (web guard) login for the Blade layer.
 *
 * The API's JWT auth is untouched — this is the second half of the dual-auth
 * model in docs/frontend-plan.md §2. Blade needs server-side auth because a
 * token held in JavaScript cannot authorise a server-rendered page: by the
 * time JS runs, the HTML has already been sent.
 *
 * The session cookie is also what makes offline work: it survives a reload
 * with no network, so the cached shell opens the morning after a night with
 * no signal — a 60-minute JWT cannot, and cannot be refreshed offline either.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // One message for both unknown-email and wrong-password: saying
            // which is wrong tells an attacker whether an account exists.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // Rotates the session id, so a session fixed before login is useless.
        $request->session()->regenerate();

        // A platform admin is not a shopkeeper — they usually hold no membership,
        // so /app would stall on an empty business picker. Send them to the console.
        // An explicit intended() target (e.g. a deep link they were bounced from)
        // still wins for everyone.
        $fallback = $request->user()->is_platform_admin ? route('admin.console') : route('app');

        return redirect()->intended($fallback);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
