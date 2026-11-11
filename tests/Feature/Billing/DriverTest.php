<?php

use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Gateways\RazorpayGateway;
use App\Modules\Billing\Providers\BillingServiceProvider;

function bootBilling(): void
{
    (new BillingServiceProvider(app()))->boot();
}

it('refuses unknown drivers instead of falling back to fake', function () {
    config(['billing.driver' => 'stripe']);

    expect(fn () => bootBilling())->toThrow(InvalidArgumentException::class, 'Unknown BILLING_DRIVER [stripe]');
    app()->forgetInstance(PaymentGateway::class);
    expect(fn () => app(PaymentGateway::class))->toThrow(InvalidArgumentException::class);
});

it('refuses the fake driver in every environment except local and testing', function () {
    app()['env'] = 'production';
    config(['billing.driver' => 'fake']);

    expect(fn () => bootBilling())->toThrow(LogicException::class, 'only allowed when APP_ENV is local or testing');

    foreach (['staging', 'qa', 'prod'] as $env) {
        app()['env'] = $env;
        expect(fn () => bootBilling())->toThrow(LogicException::class);
    }
    foreach (['local', 'testing'] as $env) {
        app()['env'] = $env;
        bootBilling();
    }
});

it('allows razorpay in production and fake outside it', function () {
    config(['billing.driver' => 'fake']);
    bootBilling();

    app()['env'] = 'production';
    config(['billing.driver' => 'razorpay']);
    app()->forgetInstance(PaymentGateway::class);
    bootBilling();

    expect(app(PaymentGateway::class))->toBeInstanceOf(RazorpayGateway::class);
});
