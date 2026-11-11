<?php

use App\Modules\Content\Http\Controllers\BlogController;
use App\Modules\Content\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('blog.show');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
