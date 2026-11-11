<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-loads every app/Modules/<Module>/routes/api.php (under /api, `api` group), routes/web.php (`web` group)
 * and Console/*Command.php. Module web routes register before routes/web.php, whose SPA catch-all comes last.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $billingOff = ! config('billing.enabled', true);
        $skip = fn (string $file): bool => $billingOff && basename(dirname($file, 2)) === 'Billing';

        foreach (glob(app_path('Modules/*/routes/api.php')) ?: [] as $file) {
            if ($skip($file)) {
                continue;
            }
            Route::middleware('api')->prefix('api')->group($file);
        }
        foreach (glob(app_path('Modules/*/routes/web.php')) ?: [] as $file) {
            if ($skip($file)) {
                continue;
            }
            Route::middleware('web')->group($file);
        }

        if ($this->app->runningInConsole()) {
            $commands = [];
            foreach (glob(app_path('Modules/*/Console/*Command.php')) ?: [] as $file) {
                $module = basename(dirname($file, 2));
                $commands[] = 'App\\Modules\\'.$module.'\\Console\\'.basename($file, '.php');
            }
            $this->commands($commands);
        }
    }
}
