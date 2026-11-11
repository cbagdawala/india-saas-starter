<?php

/*
| GST tax invoices for what this SaaS charges its workspaces. All money is integer paise.
| Every value below that identifies the seller must be set before the first invoice is issued (issuing refuses to
| run with an incomplete or invalid seller, so a half-configured install never produces a legal document).
| Nothing here is tax advice: see the "needs CA confirmation" list in README.md.
*/
return [
    // Master switch. Off = no invoices are issued and the boot check below is skipped.
    'enabled' => filter_var(env('INVOICING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Days of the webhook ledger invoicing:backfill re-examines for payments that never got an invoice.
    'backfill_days' => (int) env('INVOICING_BACKFILL_DAYS', 30),

    // The supplier on every invoice: the operator of this SaaS.
    'seller' => [
        'legal_name' => env('INVOICING_SELLER_NAME'),
        'address' => env('INVOICING_SELLER_ADDRESS'),
        'gstin' => env('INVOICING_SELLER_GSTIN'),
        'state_code' => env('INVOICING_SELLER_STATE_CODE'), // 2 digits; must match the GSTIN's first two digits
        'pan' => env('INVOICING_SELLER_PAN'),
        'email' => env('INVOICING_SELLER_EMAIL'),
    ],

    // Series prefixes. Number = PREFIX/YY-YY/NNNNN, at most 16 characters in total (CGST Rule 46).
    'series' => [
        'invoice' => env('INVOICING_PREFIX', 'INV'),
        'credit_note' => env('INVOICING_CREDIT_NOTE_PREFIX', 'CN'),
    ],
    'number_width' => 5,
    'timezone' => 'Asia/Kolkata', // financial year (1 Apr - 31 Mar) and the invoice date are taken in this zone

    // Services Accounting Code printed on lines. 998314 is the default; confirm the right code with your CA.
    'default_sac' => env('INVOICING_SAC', '998314'),

    // GST rate in percent (18 for online software services as of writing), stored as basis points.
    'default_rate_bp' => (int) round(((float) env('INVOICING_GST_RATE_PERCENT', 18)) * 100),

    // true: the plan price already includes GST (the amount the gateway charges is the invoice total).
    // false: GST is added on top of the price (the invoice total is then higher than the gateway charge).
    'prices_include_tax' => filter_var(env('INVOICING_PRICES_INCLUDE_TAX', true), FILTER_VALIDATE_BOOLEAN),

    // Buyers outside India. lut = zero-rated, no tax charged, under a Letter of Undertaking.
    // igst = charge IGST and claim the refund. Needs CA confirmation either way.
    'export' => [
        'mode' => env('INVOICING_EXPORT_MODE', 'lut'),
        'lut_note' => env('INVOICING_EXPORT_LUT_NOTE', 'Supply meant for export under Letter of Undertaking without payment of integrated tax.'),
        'igst_note' => 'Export of services with payment of integrated tax.',
    ],

    // Manual (not gateway-paid) invoices can carry a UPI deep link and QR. A link does NOT confirm payment.
    'upi' => [
        'vpa' => env('INVOICING_UPI_VPA'),
        'payee_name' => env('INVOICING_UPI_PAYEE_NAME'), // defaults to the seller legal name
    ],
];
