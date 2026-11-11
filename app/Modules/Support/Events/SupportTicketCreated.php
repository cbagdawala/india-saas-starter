<?php

namespace App\Modules\Support\Events;

use App\Modules\Support\Models\SupportTicket;
use Illuminate\Foundation\Events\Dispatchable;

class SupportTicketCreated
{
    use Dispatchable;

    public function __construct(public readonly SupportTicket $ticket) {}
}
