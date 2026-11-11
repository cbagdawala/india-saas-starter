<?php

namespace App\Modules\Auth\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'current_tenant_id' => $this->current_tenant_id,
            'email_verified' => $this->hasVerifiedEmail(),
            'onboarded_at' => $this->onboarded_at?->toIso8601String(),
        ];
    }
}
