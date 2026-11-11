<?php

use App\Modules\System\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

// SPA shell for every non-API, non-infrastructure route. `/` is the Landing module (guests) / SPA (signed in);
// /blog, /sitemap.xml, /robots.txt are the Content module.
Route::view('/{any}', 'app')
    ->where('any', '^(?!(api|sanctum|health|up|storage|build|blog|sitemap\.xml|robots\.txt)(/|$)).+$')
    ->name('spa');
