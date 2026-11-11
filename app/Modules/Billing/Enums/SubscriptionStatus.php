<?php

namespace App\Modules\Billing\Enums;

enum SubscriptionStatus: string
{
    case Created = 'created';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function isLive(): bool
    {
        return in_array($this, [self::Active, self::PastDue], true);
    }
}
