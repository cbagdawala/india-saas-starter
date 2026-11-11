<?php

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: one context per request / job, reset between them.
        $this->app->scoped(TenantContext::class);
    }
}
