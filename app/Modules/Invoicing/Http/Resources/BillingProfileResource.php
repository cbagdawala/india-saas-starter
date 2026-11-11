<?php

namespace App\Modules\Invoicing\Http\Resources;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class BillingProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'legal_name' => $this->legal_name,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'pincode' => $this->pincode,
            'country' => $this->country ?: 'IN',
            'state_code' => $this->state_code,
            'gstin' => $this->gstin,
            'complete' => $this->legal_name && $this->address_line1 && $this->city && $this->country && ($this->country !== 'IN' || $this->state_code),
        ];
    }
}
