<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_id', 'type', 'payload', 'processed_at'])]
class BillingWebhookEvent extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
