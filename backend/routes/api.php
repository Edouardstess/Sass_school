<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\StudentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SchoolFlow API v1
|--------------------------------------------------------------------------
|
| Three concentric groups, from least to most trusted:
|
|   public   — no authentication (admissions form, document verification)
|   auth     — authenticated, but not bound to a tenant (profile, platform)
|   tenant   — authenticated AND operating inside a specific school
|
| The `tenant.required` middleware is what stops a platform admin who omitted
| the X-School-Id header from reaching tenant controllers with the isolation
| scope inactive.
|
*/

Route::prefix('v1')->group(function (): void {

    // ---------------------------------------------------------------- public
    Route::get('/health', [HealthController::class, 'health'])->name('health');
    Route::get('/ready', [HealthController::class, 'ready'])->name('ready');

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth')
        ->name('auth.login');

    // ----------------------------------------------------- authenticated
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/password', [AuthController::class, 'changePassword'])->name('auth.password');

        Route::prefix('auth/two-factor')->name('auth.2fa.')->group(function (): void {
            Route::post('/', [AuthController::class, 'enableTwoFactor'])->name('enable');
            Route::post('/confirm', [AuthController::class, 'confirmTwoFactor'])->name('confirm');
            Route::delete('/', [AuthController::class, 'disableTwoFactor'])->name('disable');
        });

        // ------------------------------------------------------- tenant scope
        Route::middleware(['tenant', 'tenant.required'])->group(function (): void {

            Route::apiResource('students', StudentController::class);
            Route::post('students/{student}/guardians', [StudentController::class, 'attachGuardian'])
                ->name('students.guardians.attach');
            Route::delete('students/{student}/guardians/{guardianId}', [StudentController::class, 'detachGuardian'])
                ->name('students.guardians.detach');
        });
    });
});
