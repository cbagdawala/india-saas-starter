<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'plan', 'status', 'gateway', 'gateway_subscription_id', 'amount', 'currency', 'last_payment_id', 'current_period_end', 'cancelled_at', 'last_event_at'])]
#[UseFactory(SubscriptionFactory::class)]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToTenant, HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'amount' => 'integer',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
