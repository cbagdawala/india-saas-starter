<?php

namespace App\Modules\Support\Providers;

use App\Modules\Support\Services\SupportService;
use App\Modules\Support\Services\SupportServiceInterface;
use Illuminate\Support\ServiceProvider;

class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SupportServiceInterface::class, SupportService::class);
    }
}
