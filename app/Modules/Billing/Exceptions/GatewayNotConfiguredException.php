<?php

namespace App\Modules\Billing\Exceptions;

/** Thrown by RazorpayGateway when RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET (or a plan id) are empty. */
class GatewayNotConfiguredException extends BillingException
{
    public static function missing(string $what): self
    {
        return new self("Razorpay is not configured: {$what} is empty. Set it in .env (test-mode keys) or use BILLING_DRIVER=fake.", 503);
    }
}
