<?php

use App\Models\User;
use App\Modules\Invoicing\Models\Invoice;
use Symfony\Component\Process\Process;

/*
| Real concurrency needs real database connections from separate processes, so this runs `php artisan invoicing:issue`
| in parallel against MySQL. It is skipped on sqlite (the default test database). Run it with:
|   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=india_saas_starter DB_USERNAME=starter DB_PASSWORD=... \
|   CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync vendor/bin/pest tests/Concurrency
| It migrates fresh and WIPES that database.
*/
it('allocates gapless unique invoice numbers across parallel processes', function () {
    $user = User::factory()->create();
    $tenantId = $user->current_tenant_id;
    $workers = 12;

    $env = [
        'APP_ENV' => 'testing', 'INVOICING_SELLER_NAME' => 'Example Software Pvt Ltd', 'INVOICING_SELLER_ADDRESS' => '1 Example Road, Mumbai',
        'INVOICING_SELLER_GSTIN' => '27AAPFU0939F1ZV', 'INVOICING_SELLER_STATE_CODE' => '27', 'BILLING_ENABLED' => 'true',
    ];

    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        $p = new Process([PHP_BINARY, base_path('artisan'), 'invoicing:issue', (string) $tenantId, "Parallel {$i}", '49900'], base_path(), $env);
        $p->start();
        $processes[] = $p;
    }
    foreach ($processes as $p) {
        $p->wait();
    }

    $failed = array_filter($processes, fn (Process $p) => $p->getExitCode() !== 0);
    expect($failed)->toBe([], implode("\n", array_map(fn (Process $p) => $p->getOutput().$p->getErrorOutput(), $failed)));

    $sequences = Invoice::withoutTenancy()->orderBy('sequence')->pluck('sequence')->all();
    expect($sequences)->toBe(range(1, $workers))
        ->and(Invoice::withoutTenancy()->distinct()->count('number'))->toBe($workers);
})->skip(fn () => config('database.default') !== 'mysql', 'Needs MySQL (see the comment at the top of this file).');
