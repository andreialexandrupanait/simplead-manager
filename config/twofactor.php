<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Admin enrollment grace period
    |--------------------------------------------------------------------------
    |
    | Admins must enroll in TOTP two-factor auth (C-02). To avoid locking
    | existing admins out the moment this ships, the grace window is measured
    | from each admin's first authenticated request without 2FA. Until it
    | elapses they are nagged; after it, they are forced to the enrollment page
    | before they can use the app.
    |
    */
    'admin_grace_days' => (int) env('MFA_ADMIN_GRACE_DAYS', 7),

    /*
    | Max verification attempts per minute on the challenge screen (per user+IP).
    */
    'challenge_max_attempts' => (int) env('MFA_CHALLENGE_MAX_ATTEMPTS', 5),
];
