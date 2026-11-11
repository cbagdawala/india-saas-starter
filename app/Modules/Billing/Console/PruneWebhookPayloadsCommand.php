<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Models\BillingWebhookEvent;
use Illuminate\Console\Command;

/**
 * Scheduled daily. Webhook payloads carry gateway customer details; the ledger row (event id, type, time) is kept for
 * idempotency, the body is not needed after the retention period. Keep it longer than invoicing.backfill_days.
 */
class PruneWebhookPayloadsCommand extends Command
{
    protected $signature = 'billing:prune-webhook-payloads {--days= : Override billing.webhook_payload_days}';

    protected $description = 'Strip the payload of old billing webhook events (the idempotency row stays).';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('billing.webhook_payload_days'));
        if ($days < 1) {
            $this->info('Pruning is off (days < 1).');

            return self::SUCCESS;
        }

        $count = BillingWebhookEvent::query()->where('created_at', '<', now()->subDays($days))->where('payload', '!=', json_encode(['pruned' => true]))
            ->update(['payload' => json_encode(['pruned' => true])]);
        $this->info("Stripped {$count} webhook payload(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
