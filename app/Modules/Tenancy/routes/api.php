<?php

use App\Modules\Tenancy\Http\Controllers\InvitationController;
use App\Modules\Tenancy\Http\Controllers\MemberController;
use App\Modules\Tenancy\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:tenancy-api'])->group(function () {
    // User-level: work without a current workspace (so a user can always list, switch, create or join one).
    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('/tenants', [TenantController::class, 'store'])->middleware(['verified', 'throttle:workspace-create'])->name('tenants.store');
    Route::post('/tenants/{tenantId}/switch', [TenantController::class, 'switch'])->whereNumber('tenantId')->name('tenants.switch');
    Route::post('/invitations/accept', [TenantController::class, 'accept'])->middleware(['verified', 'throttle:invitation-accept'])->name('invitations.accept');

    // Workspace-level: resolved and authorised by the `tenant` middleware.
    Route::middleware('tenant')->prefix('tenant')->group(function () {
        Route::get('/', [TenantController::class, 'current'])->name('tenant.current');
        Route::get('/members', [MemberController::class, 'index'])->name('tenant.members.index');
        Route::patch('/members/{userId}', [MemberController::class, 'update'])->whereNumber('userId')->name('tenant.members.update');
        Route::delete('/members/{userId}', [MemberController::class, 'destroy'])->whereNumber('userId')->name('tenant.members.destroy');
        Route::post('/leave', [MemberController::class, 'leave'])->name('tenant.leave');
        Route::get('/invitations', [InvitationController::class, 'index'])->name('tenant.invitations.index');
        Route::post('/invitations', [InvitationController::class, 'store'])->middleware(['verified', 'throttle:invitation-send'])->name('tenant.invitations.store');
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation')->name('tenant.invitations.destroy');
    });
});
