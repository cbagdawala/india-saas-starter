<?php

namespace App\Modules\Invoicing\Support;

use App\Modules\Tenancy\Models\Tenant;

/** What an invoice needs to know about the workspace as a buyer. */
final class BuyerProfile
{
    /** Two-digit state code: the profile's state, else the first two digits of a valid GSTIN, else null. India only. */
    public static function stateCode(Tenant $tenant): ?string
    {
        if (strtoupper((string) ($tenant->country ?: 'IN')) !== 'IN') {
            return null;
        }
        $gstin = strtoupper((string) $tenant->gstin);

        return GstStateCodes::normalize($tenant->state_code) ?? (Gstin::isValid($gstin) ? substr($gstin, 0, 2) : null);
    }

    /** @return list<string> missing items, empty when the profile is complete enough to issue a legal invoice */
    public static function missing(Tenant $tenant): array
    {
        $missing = [];
        foreach (['legal_name' => 'legal name', 'address_line1' => 'address', 'city' => 'city'] as $field => $label) {
            if (trim((string) $tenant->{$field}) === '') {
                $missing[] = $label;
            }
        }
        if (strtoupper((string) ($tenant->country ?: 'IN')) === 'IN' && self::stateCode($tenant) === null) {
            $missing[] = 'state';
        }

        return $missing;
    }
}
