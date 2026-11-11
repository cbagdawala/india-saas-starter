<?php

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Gateways\FakeGateway;
use App\Modules\Billing\Gateways\RazorpayGateway;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Services\BillingServiceInterface;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;

class BillingServiceProvider extends ServiceProvider
{
    public const DRIVERS = ['fake', 'razorpay'];

    public function register(): void
    {
        if (! config('billing.enabled', true)) {
            return;
        }

        $this->app->singleton(PaymentGateway::class, fn () => match ($this->driver()) {
            'razorpay' => new RazorpayGateway,
            'fake' => new FakeGateway,
        });
        $this->app->bind(BillingServiceInterface::class, BillingService::class);
    }

    /** Fail fast at boot: a misconfigured driver must never silently fall back to the fake gateway. */
    public function boot(): void
    {
        if (! config('billing.enabled', true)) {
            return;
        }

        $this->driver();
    }

    private function driver(): string
    {
        $driver = config('billing.driver');

        if (! is_string($driver) || ! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown BILLING_DRIVER [%s]. Supported: %s.', is_scalar($driver) ? $driver : gettype($driver), implode(', ', self::DRIVERS)));
        }

        // Allow-list, not a production check: staging, QA and any custom APP_ENV must not run the no-network fake gateway either.
        if ($driver === 'fake' && ! $this->app->environment('local', 'testing')) {
            throw new LogicException('BILLING_DRIVER=fake is only allowed when APP_ENV is local or testing. Set BILLING_DRIVER=razorpay.');
        }

        return $driver;
    }
}
