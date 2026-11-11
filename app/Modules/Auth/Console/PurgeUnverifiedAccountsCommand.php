<?php

namespace App\Modules\Auth\Console;

use App\Models\User;
use App\Modules\Auth\Exceptions\AccountErasureBlocked;
use App\Modules\Auth\Services\AccountService;
use Illuminate\Console\Command;

/** Scheduled daily. Accounts nobody verified are almost always typos or abuse; they hold personal data for no reason. */
class PurgeUnverifiedAccountsCommand extends Command
{
    protected $signature = 'accounts:purge-unverified {--days= : Override accounts.unverified_purge_days}';

    protected $description = 'Delete accounts whose email was never verified within the configured number of days.';

    public function handle(AccountService $accounts): int
    {
        $days = (int) ($this->option('days') ?? config('accounts.unverified_purge_days'));
        if ($days < 1) {
            $this->info('Purging is off (days < 1).');

            return self::SUCCESS;
        }

        $purged = 0;
        User::query()->whereNull('email_verified_at')->where('created_at', '<', now()->subDays($days))->orderBy('id')->each(function (User $user) use ($accounts, &$purged) {
            try {
                $accounts->erase($user); // re-checks blockers under lock
                $purged++;
            } catch (AccountErasureBlocked) {
                // owns something shared or paid: leave it for a human
            }
        });

        $this->info("Purged {$purged} unverified account(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
