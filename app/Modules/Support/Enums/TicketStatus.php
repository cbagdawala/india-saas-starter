<?php

namespace App\Modules\Support\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Closed = 'closed';
}
