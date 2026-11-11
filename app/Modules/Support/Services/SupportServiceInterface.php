<?php

namespace App\Modules\Support\Services;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SupportServiceInterface
{
    /** @return LengthAwarePaginator<int, SupportTicket> the user's own tickets, newest first */
    public function paginateFor(User $user, int $perPage = 15): LengthAwarePaginator;

    /** Contact-form ticket from a signed-in user (sender verified). */
    public function createFromForm(User $user, string $subject, string $body): SupportTicket;

    /**
     * Brevo inbound-parse items.
     *
     * @param  array<int, mixed>  $items
     * @return array{created: int, duplicates: int, rejected: int}
     */
    public function ingestInbound(array $items): array;
}
