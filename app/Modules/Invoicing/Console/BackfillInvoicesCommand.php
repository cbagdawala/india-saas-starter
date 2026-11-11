<?php

namespace App\Modules\Invoicing\Console;

use App\Modules\Invoicing\Services\InvoiceBackfill;
use Illuminate\Console\Command;

/** Scheduled every 15 minutes (routes/console.php). Safe to run by hand and to run twice. */
class BackfillInvoicesCommand extends Command
{
    protected $signature = 'invoicing:backfill';

    protected $description = 'Issue invoices for confirmed payments that do not have one yet (recovers failed auto-issue).';

    public function handle(InvoiceBackfill $backfill): int
    {
        $result = $backfill->run();

        if ($result['skipped'] !== null) {
            $this->warn("Skipped: {$result['skipped']}");

            return self::FAILURE;
        }

        $this->info("Issued {$result['issued']} invoice(s); {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
