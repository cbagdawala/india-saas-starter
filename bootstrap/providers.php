<?php

use App\Modules\Billing\Providers\BillingServiceProvider;
use App\Modules\Content\Providers\ContentServiceProvider;
use App\Modules\Invoicing\Providers\InvoicingServiceProvider;
use App\Modules\Landing\Providers\LandingServiceProvider;
use App\Modules\Onboarding\Providers\OnboardingServiceProvider;
use App\Modules\Support\Providers\SupportServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    BillingServiceProvider::class,
    LandingServiceProvider::class,
    ContentServiceProvider::class,
    SupportServiceProvider::class,
    OnboardingServiceProvider::class,
    TenancyServiceProvider::class,
    InvoicingServiceProvider::class,
];
