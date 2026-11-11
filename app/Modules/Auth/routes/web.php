<?php

use App\Modules\Auth\Http\Controllers\EmailVerificationController;
use Illuminate\Support\Facades\Route;

// Link target for the verification email. Name `verification.verify` is what Laravel's VerifyEmail notification expects.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->whereNumber('id')->middleware('throttle:verification-link')->name('verification.verify');

// The emailed password-reset link (token and email in the URL fragment, see AppServiceProvider); the SPA shows the form.
Route::view('/reset-password', 'app')->name('password.reset');
