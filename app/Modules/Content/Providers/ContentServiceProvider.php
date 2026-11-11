<?php

namespace App\Modules\Content\Providers;

use App\Modules\Content\Services\ArticleService;
use App\Modules\Content\Services\ArticleServiceInterface;
use Illuminate\Support\ServiceProvider;

class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ArticleServiceInterface::class, ArticleService::class);
    }
}
