<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan' => $this->plan,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
