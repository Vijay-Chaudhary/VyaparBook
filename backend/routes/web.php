<?php

use App\Http\Controllers\Web\Admin\ConsoleController;
use App\Http\Controllers\Web\Admin\TenantActionController;
use App\Http\Controllers\Web\Admin\WhatsAppSettingsController;
use App\Http\Controllers\Web\ApiTokenController;
use App\Http\Controllers\Web\BeatController;
use App\Http\Controllers\Web\BillingController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\OnboardingController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\ExpenseController;
use App\Http\Controllers\Web\GstSettingsController;
use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\PurchaseController;
use App\Http\Controllers\Web\RegisterController;
use App\Http\Controllers\Web\ReminderController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\SupplierController;
use App\Http\Controllers\WhatsAppWebhookController;
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

/*
| WhatsApp Cloud API callbacks (Phase 4b) — delivery status and inbound replies.
|
| Deliberately outside every auth and tenant group: Meta calls this with no
| session and no tenant. Its security is the X-Hub-Signature-256 HMAC, verified
| in the controller, and a bad signature writes nothing. CSRF is exempted in
| bootstrap/app.php for the same reason — there is no session to forge against.
*/
Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle']);

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

    /*
     | Suppliers & costed raw-material purchases (Phase 2a) — Blade, online-only,
     | owner-only, same owner-tool pattern as expenses and not plan-gated.
     | {purchase}/{supplier} are resolved owner-scoped inside the controllers,
     | never via implicit binding (no tenant is pinned during route resolution).
     |
     | Deleting a purchase also reverses the stock-in it created, so on-hand
     | never overcounts — hence DELETE, routed through PurchaseWriter.
     */
    Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases');
    Route::post('purchases', [PurchaseController::class, 'store'])->name('purchases.store');
    Route::delete('purchases/{purchase}', [PurchaseController::class, 'destroy'])->name('purchases.destroy');

    /*
     | One customer's khata (read-only) — the destination for every console list
     | that names a customer. Recording sales and payments stays in the offline
     | app, so there is no store/update here.
     |
     | {customer} is resolved owner-scoped inside the controller, never via
     | implicit binding (no tenant is pinned during route resolution).
     */
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers');
    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
    Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'storePayment'])->name('suppliers.payments.store');

    /*
     | Payment reminders (Phase 4a/4b) — Blade, online-only, owner-only, same
     | owner-tool pattern as expenses/suppliers and not plan-gated.
     |
     | `send` always logs the owner's intent first, then routes by transport:
     | the default 'log' driver hands back a wa.me link (the message leaves the
     | owner's own WhatsApp), while 'cloud_api' queues SendReminderJob to send
     | from the platform number. Meta's callbacks land on /webhooks/whatsapp.
     |
     | {customer} is resolved owner-scoped inside the controller, never via
     | implicit binding (no tenant is pinned during route resolution).
     */
    Route::get('reminders', [ReminderController::class, 'index'])->name('reminders');

    // Phase 4c: automation settings and cancelling a scheduled reminder before
    // it goes out. Declared BEFORE reminders/{customer} — a literal segment
    // must win over the wildcard, or /reminders/settings resolves as a
    // customer id of "settings".
    Route::post('reminders/settings', [ReminderController::class, 'settings'])->name('reminders.settings');
    Route::post('reminders/planned/{reminder}/cancel', [ReminderController::class, 'cancelPlanned'])->name('reminders.cancel');

    Route::post('reminders/{customer}', [ReminderController::class, 'send'])->name('reminders.send');
    Route::post('reminders/{customer}/opt-out', [ReminderController::class, 'optOut'])->name('reminders.opt_out');
    Route::post('reminders/{customer}/opt-in', [ReminderController::class, 'optIn'])->name('reminders.opt_in');

    /*
     | Beat planning (PRD Phase 3) — Blade, online-only, owner-only.
     |
     | Planning happens here; the salesman's phone only READS the result through
     | the delta pull, so there is no push path and no conflict rule in the
     | field. {beat} is resolved owner-scoped inside the controller.
     */
    Route::get('beats', [BeatController::class, 'index'])->name('beats');
    Route::post('beats', [BeatController::class, 'store'])->name('beats.store');
    Route::post('beats/{beat}/customers', [BeatController::class, 'updateCustomers'])->name('beats.customers');
    Route::post('beats/{beat}/archive', [BeatController::class, 'archive'])->name('beats.archive');

    /*
     | GST invoicing (PRD Phase 3) — Blade, online-only, owner-only.
     |
     | Online by necessity, not habit: issuing allocates a GAPLESS invoice
     | number, which an offline device cannot safely do. Sales stay
     | offline-first; only invoicing one needs a connection.
     |
     | {invoice} is resolved owner-scoped inside the controller, never via
     | implicit binding (no tenant is pinned during route resolution).
     */
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices');
    Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');

    Route::get('gst', [GstSettingsController::class, 'edit'])->name('gst');
    Route::post('gst', [GstSettingsController::class, 'update'])->name('gst.save');

    /*
     | Order acceptance — Blade, online-only, owner/admin. This is deliberately
     | the only online step in the order workflow: it is the sync boundary the
     | field depends on. {order} is resolved owner-scoped inside the controller.
     */
    Route::get('orders', [OrderController::class, 'index'])->name('orders');
    Route::post('orders/{order}/accept', [OrderController::class, 'accept'])->name('orders.accept');
    Route::post('orders/{order}/reject', [OrderController::class, 'reject'])->name('orders.reject');

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

    /*
     | Platform WhatsApp credentials — one configuration for the one platform
     | number (Phase 4a Decision 3). Declared BEFORE console/{id} so the literal
     | segment is not swallowed by the tenant-id wildcard.
     */
    Route::get('console/whatsapp', [WhatsAppSettingsController::class, 'edit'])->name('console.whatsapp');
    Route::post('console/whatsapp', [WhatsAppSettingsController::class, 'update'])->name('console.whatsapp.save');
    Route::post('console/whatsapp/test', [WhatsAppSettingsController::class, 'test'])->name('console.whatsapp.test');

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
