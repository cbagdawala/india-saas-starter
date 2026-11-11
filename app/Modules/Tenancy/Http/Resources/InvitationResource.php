<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\TenantInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The token and its hash are never returned. @mixin TenantInvitation */
class InvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role->value,
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
