<?php

namespace App\Modules\Onboarding\Providers;

use App\Modules\Onboarding\Services\OnboardingService;
use App\Modules\Onboarding\Services\OnboardingServiceInterface;
use Illuminate\Support\ServiceProvider;

class OnboardingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OnboardingServiceInterface::class, OnboardingService::class);
    }
}
