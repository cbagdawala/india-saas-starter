<?php

it('fails clearly when no DSN is configured', function () {
    config(['sentry.dsn' => null]);

    $this->artisan('app:error-report-test')->expectsOutputToContain('SENTRY_LARAVEL_DSN is empty')->assertFailed();
});
