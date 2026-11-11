<?php

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Invoicing\Support\BuyerProfile;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    /** Only the workspace owner changes billing. */
    public function authorize(): bool
    {
        return Gate::allows('manageBilling', app(TenantContext::class)->tenantOrFail());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['plan' => ['required', 'string', Rule::in(array_keys((array) config('billing.plans')))]];
    }

    /**
     * The invoice for this payment is immutable, so its buyer (name, address, state: CGST + SGST versus IGST) must be right
     * before money moves. Reported as 422 `errors.billing_profile`, which the SPA turns into a redirect to the profile form.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! config('invoicing.enabled')) {
                return;
            }
            $missing = BuyerProfile::missing(app(TenantContext::class)->tenantOrFail());
            if ($missing !== []) {
                $validator->errors()->add('billing_profile', 'Complete your billing profile ('.implode(', ', $missing).') before subscribing, so your GST invoice is correct.');
            }
        }];
    }
}
