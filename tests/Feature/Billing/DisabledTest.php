<?php

use App\Models\User;
use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Providers\BillingServiceProvider;
use App\Modules\Onboarding\Services\OnboardingServiceInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;

it('boots in production without a billing driver when billing is disabled', function () {
    app()['env'] = 'production';
    config(['billing.enabled' => false, 'billing.driver' => 'fake']);

    $provider = new BillingServiceProvider(app());
    $provider->register();
    $provider->boot();

    expect(true)->toBeTrue();
});

it('still refuses the fake driver in production when billing is enabled', function () {
    app()['env'] = 'production';
    config(['billing.enabled' => true, 'billing.driver' => 'fake']);

    expect(fn () => (new BillingServiceProvider(app()))->boot())->toThrow(LogicException::class, 'only allowed when APP_ENV is local or testing');
    app()->forgetInstance(PaymentGateway::class);
    expect(fn () => app(PaymentGateway::class))->toThrow(LogicException::class);
});

it('registers no billing routes and boots in production when BILLING_ENABLED=false', function () {
    expect(Route::has('billing.plans'))->toBeTrue();

    // Restore exactly what was there before (phpunit.xml forces BILLING_ENABLED=true; unsetting it would let .env leak in).
    $keys = ['BILLING_ENABLED', 'BILLING_DRIVER', 'APP_ENV', 'APP_DEBUG'];
    $before = [];
    foreach ($keys as $k) {
        $before[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null, getenv($k)];
    }

    $_ENV['BILLING_ENABLED'] = $_SERVER['BILLING_ENABLED'] = 'false';
    $_ENV['BILLING_DRIVER'] = $_SERVER['BILLING_DRIVER'] = 'fake';
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
    $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = 'false';
    putenv('BILLING_ENABLED=false');
    putenv('APP_ENV=production');
    putenv('APP_DEBUG=false');

    try {
        $app = require base_path('bootstrap/app.php');
        $app->make(Kernel::class)->bootstrap();

        expect($app['config']->get('billing.enabled'))->toBeFalse()
            ->and($app->isProduction())->toBeTrue()
            ->and($app['router']->has('billing.plans'))->toBeFalse()
            ->and($app['router']->has('billing.webhook'))->toBeFalse();
    } finally {
        foreach ($before as $k => [$env, $server, $process]) {
            if ($env === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $server;
            }
            putenv($process === false ? $k : $k.'='.$process);
        }
    }
});

it('degrades landing pricing and onboarding when billing is disabled', function () {
    config(['billing.enabled' => false]);

    $this->withoutVite()->get('/')->assertOk()->assertDontSee('id="pricing"', false);

    $user = User::factory()->create();
    $steps = app(OnboardingServiceInterface::class)->state($user)['steps'];
    expect(collect($steps)->pluck('key'))->not->toContain('plan');
});
