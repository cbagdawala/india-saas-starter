<?php

return [
    // false = product has no billing: no driver resolved/validated at boot, no billing routes.
    'enabled' => filter_var(env('BILLING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // fake | razorpay
    'driver' => env('BILLING_DRIVER', 'fake'),

    'currency' => 'INR',

    // Days to keep webhook event bodies (the ledger row stays for idempotency). Keep above invoicing.backfill_days.
    'webhook_payload_days' => (int) env('BILLING_WEBHOOK_PAYLOAD_DAYS', 90),

    // Webhook events whose created_at is older than this many hours are recorded but not applied (replay protection). 0 = off.
    'webhook_max_age_hours' => (int) env('BILLING_WEBHOOK_MAX_AGE_HOURS', 48),

    // Amounts are in paise (INR minor units). razorpay_plan_id is the plan created in the Razorpay dashboard (test mode).
    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'amount' => 49900, // ₹499 / month
            'currency' => 'INR',
            'interval' => 'month',
            'razorpay_plan_id' => env('RAZORPAY_PLAN_STARTER'),
            'features' => ['1 project', 'Email support'],
        ],
        'pro' => [
            'name' => 'Pro',
            'amount' => 149900, // ₹1,499 / month
            'currency' => 'INR',
            'interval' => 'month',
            'razorpay_plan_id' => env('RAZORPAY_PLAN_PRO'),
            'features' => ['Unlimited projects', 'Priority support'],
        ],
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com/v1'),
        'total_count' => (int) env('RAZORPAY_TOTAL_COUNT', 120), // billing cycles per subscription
    ],
];
