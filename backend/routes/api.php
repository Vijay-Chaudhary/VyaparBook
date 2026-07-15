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

        Route::get('whoami', function () {
            return response()->json([
                'user_id' => app('tenant.user_id'),
                'tenant_id' => app('tenant.id'),
                'role' => app('tenant.role'),
            ]);
        });
    });
});
