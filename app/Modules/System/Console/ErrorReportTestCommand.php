<?php

namespace App\Modules\System\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sentry\SentrySdk;

class ErrorReportTestCommand extends Command
{
    protected $signature = 'app:error-report-test {--message=GlitchTip test exception from India SaaS Starter Kit}';

    protected $description = 'Send a test exception to GlitchTip (SENTRY_LARAVEL_DSN) to verify error reporting';

    public function handle(): int
    {
        if (! config('sentry.dsn')) {
            $this->error('SENTRY_LARAVEL_DSN is empty. Set it in .env.');

            return self::FAILURE;
        }

        $eventId = app('sentry')->captureException(new RuntimeException((string) $this->option('message')));
        SentrySdk::getCurrentHub()->getClient()?->flush();

        if ($eventId === null) {
            $this->error('The Sentry client did not accept the event.');

            return self::FAILURE;
        }

        $this->info("Sent test exception to GlitchTip (event id {$eventId}).");

        return self::SUCCESS;
    }
}
