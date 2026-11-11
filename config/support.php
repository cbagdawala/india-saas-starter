<?php

return [
    // Shared secret for POST /api/support/inbound (Brevo inbound parse webhook), >= 32 chars; empty = refuse all.
    // Sent as `Authorization: Bearer <token>` or basic auth (any user, token as password). Never as a query string.
    'inbound_token' => env('SUPPORT_INBOUND_TOKEN'),

    // authserv-id of the receiving MX whose Authentication-Results header is trusted (first token of the header,
    // e.g. the Brevo inbound host). Empty = no inbound sender is ever verified (tickets are not linked to users).
    'trusted_authserv_id' => env('SUPPORT_TRUSTED_AUTHSERV_ID'),

    // Max raw webhook body in bytes (Brevo sends attachment metadata only, not the files).
    'max_body_bytes' => 1048576,

    // Stored body text is truncated to this many characters.
    'max_body_chars' => 20000,

    // Public support address shown in the UI and landing FAQ (optional).
    'address' => env('SUPPORT_ADDRESS'),
];
