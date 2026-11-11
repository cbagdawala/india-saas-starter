<?php

use App\Modules\Support\Http\Controllers\InboundEmailController;
use App\Modules\Support\Http\Controllers\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('support')->group(function () {
    Route::post('/inbound', InboundEmailController::class)
        ->middleware(['throttle:support-inbound', 'body.limit:'.config('support.max_body_bytes', 1048576)])
        ->name('support.inbound');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('/tickets', [SupportTicketController::class, 'index'])->name('support.tickets.index');
        Route::post('/tickets', [SupportTicketController::class, 'store'])->middleware('throttle:support-ticket')->name('support.tickets.store');
    });
});
