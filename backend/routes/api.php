<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [\App\Http\Controllers\Api\V1\AuthController::class, 'register']);
    Route::post('auth/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login']);
    Route::post('auth/otp/request', [\App\Http\Controllers\Api\V1\OtpController::class, 'request']);
    Route::post('auth/otp/verify', [\App\Http\Controllers\Api\V1\OtpController::class, 'verify']);

    Route::middleware(['auth:api', 'tenant.context'])->group(function () {
        Route::post('businesses', [\App\Http\Controllers\Api\V1\BusinessController::class, 'store']);
        Route::get('businesses/mine', [\App\Http\Controllers\Api\V1\BusinessController::class, 'mine']);
        Route::post('businesses/{id}/switch', [\App\Http\Controllers\Api\V1\BusinessController::class, 'switch']);
        Route::post('invites/accept', [\App\Http\Controllers\Api\V1\InviteController::class, 'accept']);

        // Billing stays OUT of the plan.gate: an owner in read_only (dunning) must
        // still view billing and record a payment to recover. Owner-only gating is
        // in the controller (BillingPolicy).
        Route::middleware(['require.tenant'])->group(function () {
            Route::get('billing', [\App\Http\Controllers\Api\V1\BillingController::class, 'show']);
            Route::post('billing/payments', [\App\Http\Controllers\Api\V1\BillingController::class, 'recordPayment']);
        });

        // Domain routes: writes are paused (402) for a read_only tenant; GET/HEAD
        // pass through the gate. plan.gate sits inside require.tenant so the tenant
        // is already pinned when it reads the subscription.
        Route::middleware(['require.tenant', 'plan.gate'])->group(function () {
            Route::post('businesses/{id}/invite', [\App\Http\Controllers\Api\V1\InviteController::class, 'store']);

            Route::post('products', [\App\Http\Controllers\Api\V1\ProductController::class, 'store']);
            Route::patch('products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'update']);
            Route::delete('products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'destroy']);
            Route::post('products/{id}/restore', [\App\Http\Controllers\Api\V1\ProductController::class, 'restore']);

            Route::post('pack-sizes', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'store']);
            Route::patch('pack-sizes/{id}', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'update']);
            Route::delete('pack-sizes/{id}', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'destroy']);
            Route::post('pack-sizes/{id}/restore', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'restore']);

            Route::post('product-packs', [\App\Http\Controllers\Api\V1\ProductPackController::class, 'store']);
            Route::patch('product-packs/{id}', [\App\Http\Controllers\Api\V1\ProductPackController::class, 'update']);
            Route::delete('product-packs/{id}', [\App\Http\Controllers\Api\V1\ProductPackController::class, 'destroy']);
            Route::post('product-packs/{id}/restore', [\App\Http\Controllers\Api\V1\ProductPackController::class, 'restore']);

            Route::get('catalog', [\App\Http\Controllers\Api\V1\CatalogController::class, 'index']);
            Route::post('catalog/seed', [\App\Http\Controllers\Api\V1\CatalogController::class, 'seed']);

            Route::post('customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'store']);
            Route::patch('customers/{id}', [\App\Http\Controllers\Api\V1\CustomerController::class, 'update']);
            Route::delete('customers/{id}', [\App\Http\Controllers\Api\V1\CustomerController::class, 'destroy']);
            Route::post('customers/{id}/restore', [\App\Http\Controllers\Api\V1\CustomerController::class, 'restore']);

            Route::post('sales', [\App\Http\Controllers\Api\V1\SaleController::class, 'store']);
            Route::post('sales/{id}/void', [\App\Http\Controllers\Api\V1\SaleController::class, 'void']);

            Route::post('payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'store']);
            Route::post('payments/{id}/reverse', [\App\Http\Controllers\Api\V1\PaymentController::class, 'reverse']);

            Route::get('khata', [\App\Http\Controllers\Api\V1\KhataController::class, 'index']);
            Route::get('khata/{id}', [\App\Http\Controllers\Api\V1\KhataController::class, 'show']);

            // Stock & production — owner/admin only (gated in each controller via
            // StockPolicy::manage(), reads included; PRD §7).
            Route::post('raw-materials', [\App\Http\Controllers\Api\V1\RawMaterialController::class, 'store']);
            Route::patch('raw-materials/{id}', [\App\Http\Controllers\Api\V1\RawMaterialController::class, 'update']);
            Route::delete('raw-materials/{id}', [\App\Http\Controllers\Api\V1\RawMaterialController::class, 'destroy']);
            Route::post('raw-materials/{id}/restore', [\App\Http\Controllers\Api\V1\RawMaterialController::class, 'restore']);

            Route::post('stock-movements', [\App\Http\Controllers\Api\V1\StockMovementController::class, 'store']);
            Route::get('stock', [\App\Http\Controllers\Api\V1\StockController::class, 'index']);
            Route::get('stock/{id}', [\App\Http\Controllers\Api\V1\StockController::class, 'show']);

            Route::post('production', [\App\Http\Controllers\Api\V1\ProductionController::class, 'store']);
            Route::get('production', [\App\Http\Controllers\Api\V1\ProductionController::class, 'index']);
            Route::get('production/{id}', [\App\Http\Controllers\Api\V1\ProductionController::class, 'show']);

            Route::post('sync/push', [\App\Http\Controllers\Api\V1\SyncController::class, 'push']);
            Route::get('sync/pull', [\App\Http\Controllers\Api\V1\SyncController::class, 'pull']);
        });

        Route::get('whoami', function () {
            return response()->json([
                'user_id' => app('tenant.user_id'),
                'tenant_id' => app('tenant.id'),
                'role' => app('tenant.role'),
            ]);
        });
    });

    // Platform (Superadmin) console — gated on the live is_platform_admin flag,
    // NOT a tenant membership. Deliberately OUTSIDE the tenant.context group:
    // these routes are cross-tenant and carry no tenant GUC.
    Route::middleware(['auth:api', 'require.platform_admin'])->prefix('admin')->group(function () {
        Route::get('tenants', [\App\Http\Controllers\Api\V1\Admin\TenantController::class, 'index']);
        Route::get('tenants/{id}', [\App\Http\Controllers\Api\V1\Admin\TenantController::class, 'show']);
    });
});
