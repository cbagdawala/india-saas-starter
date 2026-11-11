<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Responses\ApiResponse;
use App\Modules\Billing\Http\Requests\CheckoutRequest;
use App\Modules\Billing\Http\Requests\ConfirmPaymentRequest;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Services\BillingServiceInterface;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BillingController extends Controller
{
    public function __construct(private readonly BillingServiceInterface $billing, private readonly TenantContext $tenant) {}

    public function plans(): JsonResponse
    {
        return ApiResponse::success('Plans', array_values($this->billing->plans()));
    }

    public function subscription(Request $request): JsonResponse
    {
        $subscription = $this->billing->currentSubscription($request->user());

        return ApiResponse::success('Current subscription', $subscription ? new SubscriptionResource($subscription) : null);
    }

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        $checkout = $this->billing->checkout($request->user(), $request->validated('plan'));

        return ApiResponse::created('Checkout created', $checkout->toArray());
    }

    public function confirm(ConfirmPaymentRequest $request): JsonResponse
    {
        Gate::authorize('manageBilling', $this->tenant->tenantOrFail());
        $subscription = $this->billing->confirm($request->user(), $request->validated());

        return ApiResponse::success('Payment confirmed', new SubscriptionResource($subscription));
    }

    public function cancel(Request $request): JsonResponse
    {
        Gate::authorize('manageBilling', $this->tenant->tenantOrFail());
        $subscription = $this->billing->cancel($request->user());

        return ApiResponse::success('Subscription cancelled', new SubscriptionResource($subscription));
    }
}
