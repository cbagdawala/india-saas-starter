<?php

use App\Modules\Billing\Http\Controllers\BillingController;
use App\Modules\Billing\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('billing')->group(function () {
    Route::post('/webhook', WebhookController::class)->middleware('throttle:billing-webhook')->name('billing.webhook');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('/plans', [BillingController::class, 'plans'])->name('billing.plans');
        Route::get('/subscription', [BillingController::class, 'subscription'])->name('billing.subscription');
        Route::post('/checkout', [BillingController::class, 'checkout'])->middleware(['verified', 'throttle:billing-checkout'])->name('billing.checkout');
        Route::post('/confirm', [BillingController::class, 'confirm'])->middleware('throttle:billing-confirm')->name('billing.confirm');
        Route::post('/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    });
});
