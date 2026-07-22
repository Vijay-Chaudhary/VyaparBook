<?php

use App\Http\Controllers\Web\Admin\ConsoleController;
use App\Http\Controllers\Web\Admin\TenantActionController;
use App\Http\Controllers\Web\ApiTokenController;
use App\Http\Controllers\Web\BillingController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\OnboardingController;
use App\Http\Controllers\Web\ExpenseController;
use App\Http\Controllers\Web\RegisterController;
use App\Http\Controllers\Web\ReportController;
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

    /*
     | Billing & plan (docs/frontend-plan.md §7 Phase 6) — Blade, online-only.
     | Owner-only, enforced in the controller by resolving the caller's OWNED
     | business (never a request-supplied one). Deliberately not behind the plan
     | gate: an owner in dunning must reach this page to record a payment.
     */
    Route::get('billing', [BillingController::class, 'show'])->name('billing');
    Route::post('billing/payment', [BillingController::class, 'storePayment'])->name('billing.payment');

    /*
     | Owner management dashboard (Phase 0) — Blade, online-only, owner-only.
     | Read-only, so intentionally NOT behind the plan gate: a lapsed owner may
     | still read their reports, like the billing page stays reachable.
     */
    Route::get('reports/dashboard', [ReportController::class, 'show'])->name('reports.dashboard');

    /*
     | Operating expenses (Phase 1) — Blade, online-only, owner-only. Same
     | owner-tool pattern as billing/reports; not behind the plan gate.
     | {expense} is resolved owner-scoped inside the controller, never via
     | implicit binding (no tenant is pinned during route resolution).
     */
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

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

/*
 | Platform (Superadmin) console (docs/frontend-plan.md §7 Phase 7) — Blade,
 | online-only. Gated on the LIVE is_platform_admin flag (platform_admin.web),
 | NOT a tenant membership: the console is cross-tenant and carries no tenant
 | GUC. Reads run on the BYPASSRLS connection; writes pin the target tenant and
 | go through RLS (PlatformTenantContext). The server-rendered twin of the JWT
 | /api/v1/admin/* surface, sharing the same service seams.
 */
Route::middleware(['auth', 'platform_admin.web'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('console', [ConsoleController::class, 'index'])->name('console');
    Route::get('console/{id}', [ConsoleController::class, 'show'])->name('console.show');

    Route::post('console/{id}/suspend', [TenantActionController::class, 'suspend'])->name('console.suspend');
    Route::post('console/{id}/reactivate', [TenantActionController::class, 'reactivate'])->name('console.reactivate');
    Route::post('console/{id}/payments/{paymentId}/verify', [TenantActionController::class, 'verifyPayment'])->name('console.payment.verify');
    Route::post('console/{id}/payments/{paymentId}/reject', [TenantActionController::class, 'rejectPayment'])->name('console.payment.reject');
    Route::post('console/{id}/impersonate', [TenantActionController::class, 'impersonate'])->name('console.impersonate');

    // Ends a "view as tenant" session. POSTed from the /app impersonation banner
    // (after the client wipes the tenant's local cache), then back to the console.
    Route::post('impersonation/exit', [TenantActionController::class, 'exitImpersonation'])->name('impersonation.exit');
});
