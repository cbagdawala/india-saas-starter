<?php

namespace App\Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Invoicing\Http\Requests\UpdateBillingProfileRequest;
use App\Modules\Invoicing\Http\Resources\BillingProfileResource;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** The workspace's invoice buyer details. Owner and admin only: a GSTIN and address are business-sensitive. */
class BillingProfileController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        $tenant = $this->context->tenantOrFail();
        Gate::authorize('manageBillingProfile', $tenant);

        return ApiResponse::success('Billing profile', new BillingProfileResource($tenant));
    }

    public function update(UpdateBillingProfileRequest $request): JsonResponse
    {
        $tenant = $this->context->tenantOrFail();
        Gate::authorize('manageBillingProfile', $tenant);

        // Issued invoices keep their own snapshot, so editing the profile never changes an existing invoice.
        $tenant->forceFill($request->validated())->save();

        return ApiResponse::success('Billing profile updated', new BillingProfileResource($tenant->refresh()));
    }
}
