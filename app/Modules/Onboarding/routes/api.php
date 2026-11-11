<?php

use App\Modules\Onboarding\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('onboarding')->group(function () {
    Route::get('/', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
});
