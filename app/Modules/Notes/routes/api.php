<?php

use App\Modules\Notes\Http\Controllers\NoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant', 'throttle:notes-api'])->apiResource('notes', NoteController::class);
