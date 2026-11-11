<?php

return [
    'login' => [
        // Hard limit per email + IP address, per minute (stops one client guessing passwords).
        'per_ip_limit' => (int) env('LOGIN_PER_IP_LIMIT', 5),
        // Failures per email, across every IP, in an hour. From `delay_after` on, each further failure doubles a lock-out
        // (1 s, 2 s, 4 s ... up to `max_delay` seconds) instead of locking the account outright. `ceiling` is the hard stop.
        // Both only apply to browsers that have not logged in to that account before (signed known-device cookie), so
        // someone hammering an address from botnet IPs cannot lock the real owner out of their own account.
        'delay_after' => (int) env('LOGIN_DELAY_AFTER', 10),
        'max_delay' => (int) env('LOGIN_MAX_DELAY', 900),
        'ceiling' => (int) env('LOGIN_EMAIL_CEILING', 100),
        'known_device_days' => 365,
    ],

    // Accounts that never verified their email are deleted after this many days (accounts:purge-unverified, daily).
    'unverified_purge_days' => (int) env('ACCOUNTS_UNVERIFIED_PURGE_DAYS', 14),
];
