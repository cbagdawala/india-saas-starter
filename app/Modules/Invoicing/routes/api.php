<?php

use App\Modules\Invoicing\Http\Controllers\BillingProfileController;
use App\Modules\Invoicing\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant', 'throttle:invoicing-api'])->group(function () {
    Route::get('/billing-profile', [BillingProfileController::class, 'show'])->name('billing-profile.show');
    Route::put('/billing-profile', [BillingProfileController::class, 'update'])->name('billing-profile.update');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('invoice')->middleware('throttle:invoice-pdf')->name('invoices.pdf');
});
