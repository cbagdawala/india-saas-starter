<?php

namespace App\Modules\Landing\Providers;

use App\Modules\Landing\Services\LandingService;
use App\Modules\Landing\Services\LandingServiceInterface;
use Illuminate\Support\ServiceProvider;

class LandingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LandingServiceInterface::class, LandingService::class);
    }
}
