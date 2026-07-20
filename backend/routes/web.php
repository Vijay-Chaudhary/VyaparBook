<?php

use App\Http\Controllers\Web\ApiTokenController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\OnboardingController;
use App\Http\Controllers\Web\RegisterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Blade routes (session auth)
|--------------------------------------------------------------------------
|
| The server-rendered half of the frontend (docs/frontend-plan.md §1).
| Protected by the `web` guard, so a page can be authorised before its HTML is
| sent — something a JS-held JWT cannot do.
|
| The JSON API in routes/api.php stays JWT-only and untouched.
|
*/

Route::get('/', fn () => redirect()->route('app'))->name('home');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');

    // Throttled by the same limiter as the API login, so the Blade form is not
    // a softer door onto the same credentials.
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login');

    Route::get('register', [RegisterController::class, 'show'])->name('register');
    // Account creation is a natural credential-stuffing / spam target, so it
    // rides the same per-email+IP limiter as login.
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:login');
});

// Onboarding: signed in (session), but a tenant does not exist yet at step 1.
Route::middleware('auth')->group(function () {
    Route::get('onboarding/business', [OnboardingController::class, 'business'])->name('onboarding.business');
    Route::post('onboarding/business', [OnboardingController::class, 'storeBusiness']);

    Route::get('onboarding/template', [OnboardingController::class, 'template'])->name('onboarding.template');
    Route::post('onboarding/template', [OnboardingController::class, 'storeTemplate']);

    Route::get('onboarding/invite', [OnboardingController::class, 'invite'])->name('onboarding.invite');
    Route::post('onboarding/invite', [OnboardingController::class, 'storeInvite']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // Session -> JWT exchange for the React layer. Throttled because it mints
    // credentials: a valid session should not make it freely spammable.
    Route::get('auth/token', [ApiTokenController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.token');

    /*
     | The single cached shell for every offline-capable screen. React handles
     | routing inside it. Intentionally ONE Blade route so the service worker
     | has exactly one document to cache — a Blade page per screen would
     | multiply the offline surface for no benefit.
     */
    Route::get('/app/{path?}', fn () => view('app'))
        ->where('path', '.*')
        ->name('app');
});
