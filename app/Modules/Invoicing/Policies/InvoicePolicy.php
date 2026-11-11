<?php

namespace App\Modules\Invoicing\Policies;

use App\Models\User;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Tenancy\TenantContext;

/** Any member of the workspace may list and download its invoices (owner/admin-only is the billing profile, see TenantPolicy). */
class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        $current = app(TenantContext::class)->id();

        return $current !== null && (int) $invoice->tenant_id === $current;
    }
}
