<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class TenantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'role' => $this->whenPivotLoaded('tenant_user', fn () => $this->pivot->role),
            'is_current' => (int) $request->user()?->current_tenant_id === (int) $this->id,
        ];
    }
}
