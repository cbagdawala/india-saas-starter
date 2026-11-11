<?php

namespace App\Modules\Invoicing\Support;

/** Validation of the INVOICING_SELLER_* settings, shared by issuing, the boot check, /health and the backfill. */
final class SellerConfig
{
    /** @return list<string> human readable problems; empty = the seller is valid */
    public static function problems(): array
    {
        $s = (array) config('invoicing.seller');
        $gstin = strtoupper(trim((string) ($s['gstin'] ?? '')));
        $state = GstStateCodes::normalize($s['state_code'] ?? null);
        $problems = [];

        if (trim((string) ($s['legal_name'] ?? '')) === '') {
            $problems[] = 'INVOICING_SELLER_NAME is empty';
        }
        if (trim((string) ($s['address'] ?? '')) === '') {
            $problems[] = 'INVOICING_SELLER_ADDRESS is empty';
        }
        if (! Gstin::isValid($gstin)) {
            $problems[] = 'INVOICING_SELLER_GSTIN is missing or not a valid GSTIN';
        }
        if ($state === null) {
            $problems[] = 'INVOICING_SELLER_STATE_CODE is missing or not a GST state code';
        } elseif (Gstin::isValid($gstin) && $state !== substr($gstin, 0, 2)) {
            $problems[] = 'INVOICING_SELLER_STATE_CODE does not match the GSTIN';
        }

        return $problems;
    }

    /** Is invoicing expected to work in this deployment (real billing driver, feature on)? */
    public static function required(): bool
    {
        return (bool) config('invoicing.enabled') && (bool) config('billing.enabled') && config('billing.driver') !== 'fake';
    }
}
