<?php

use App\Modules\Auth\Http\Controllers\AccountController;
use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\EmailVerificationController;
use App\Modules\Auth\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register')->name('auth.register');
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:forgot-password')->name('auth.password.forgot');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:reset-password')->name('auth.password.reset');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:verification-resend')->name('auth.verification.send');
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
    });
});

Route::middleware(['auth:sanctum', 'verified', 'throttle:account-api'])->prefix('account')->group(function () {
    Route::get('/export', [AccountController::class, 'export'])->middleware('throttle:account-export')->name('account.export');
    Route::delete('/', [AccountController::class, 'destroy'])->middleware('throttle:account-delete')->name('account.destroy');
});
