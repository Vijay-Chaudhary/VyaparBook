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

        Route::middleware(['require.tenant'])->group(function () {
            Route::post('businesses/{id}/invite', [\App\Http\Controllers\Api\V1\InviteController::class, 'store']);

            Route::post('products', [\App\Http\Controllers\Api\V1\ProductController::class, 'store']);
            Route::patch('products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'update']);
            Route::delete('products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'destroy']);
            Route::post('products/{id}/restore', [\App\Http\Controllers\Api\V1\ProductController::class, 'restore']);

            Route::post('pack-sizes', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'store']);
            Route::patch('pack-sizes/{id}', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'update']);
            Route::delete('pack-sizes/{id}', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'destroy']);
            Route::post('pack-sizes/{id}/restore', [\App\Http\Controllers\Api\V1\PackSizeController::class, 'restore']);
        });

        Route::get('whoami', function () {
            return response()->json([
                'user_id' => app('tenant.user_id'),
                'tenant_id' => app('tenant.id'),
                'role' => app('tenant.role'),
            ]);
        });
    });
});
