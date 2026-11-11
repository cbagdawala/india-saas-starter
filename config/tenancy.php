<?php

return [
    // Days an emailed workspace invitation stays valid.
    'invitation_ttl_days' => (int) env('TENANCY_INVITATION_TTL_DAYS', 7),

    // Cap on open (unaccepted, unexpired) invitations per workspace, so the invite endpoint cannot be used to spam.
    'max_pending_invitations' => (int) env('TENANCY_MAX_PENDING_INVITATIONS', 50),

    // Invitations a workspace may create per rolling 24 hours (revoked ones count), against use as a mail relay.
    // Invitations one person may send per rolling 24 hours across all their workspaces (revoked ones count).
    'max_invitations_per_user_per_day' => (int) env('TENANCY_MAX_INVITATIONS_PER_USER_PER_DAY', 50),

    'max_invitations_per_day' => (int) env('TENANCY_MAX_INVITATIONS_PER_DAY', 100),
];
