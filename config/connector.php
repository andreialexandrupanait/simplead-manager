<?php

declare(strict_types=1);

return [
    'changelog' => [
        'unreleased' => [
            'changes' => [
                // New changes accumulate here until version bump
            ],
        ],
        '2.9.10' => [
            'date' => '2026-03-22',
            'changes' => [
                'UI: Redesigned admin dashboard with 2-column grid layout',
                'Fix: CSS specificity with !important for WP admin compatibility',
            ],
        ],
        '2.9.8' => [
            'date' => '2026-03-22',
            'changes' => [
                'New: Standalone opcache-flush.php for automatic OPcache clearing after push',
                'Fix: Push now calls opcache-flush.php directly (bypasses WordPress/OPcache)',
            ],
        ],
        '2.9.7' => [
            'date' => '2026-03-22',
            'changes' => [
                'New: /flush-opcache endpoint for reliable OPcache clearing after push',
                'Fix: Push now auto-flushes OPcache on target site after connector update',
            ],
        ],
        '2.9.6' => [
            'date' => '2026-03-22',
            'changes' => [
                'Fix: Aggressive OPcache clearing after self-update (touch + invalidate each file)',
                'Fix: Store connector version in DB option to survive OPcache issues',
                'Fix: MU-plugin detects OPcache flag and invalidates stale connector files',
                'Fix: Info endpoint reads version from DB option as fallback',
            ],
        ],
        '2.9.5' => [
            'date' => '2026-03-22',
            'changes' => [
                'Fix: Read plugin/theme versions directly from files to bypass OPcache/object cache',
                'Fix: version_compare() verification prevents false update notifications',
            ],
        ],
        '2.9.4' => [
            'date' => '2026-03-22',
            'changes' => [
                'Fix: Update response now returns version info (from_version, to_version)',
                'Fix: Already-up-to-date plugins/themes treated as success instead of failure',
                'Fix: Plugin/theme list forces fresh update transient for accurate detection',
            ],
        ],
        '2.9.3' => [
            'date' => '2026-03-21',
            'changes' => [
                'Fix: Plugin validation now clears cache before checking (symlink compatible)',
                'Fix: Plugin list cache cleared before sync for accurate results',
            ],
        ],
        '2.9.2' => [
            'date' => '2026-03-21',
            'changes' => [
                'Fix: Invalid plugin path error on activate/deactivate/delete',
            ],
        ],
        '2.9.1' => [
            'date' => '2026-03-21',
            'changes' => [
                'Fix: Plugins no longer get deactivated during updates',
                'Fix: Custom login URL page now loads CSS/JS correctly',
                'Fix: wp-login.php and wp-admin no longer reveal custom login URL',
                'Renamed plugin to SAD Mentenanta',
            ],
        ],
        '2.9.0' => [
            'date' => '2026-03-15',
            'changes' => [
                'MU-plugin persistence for security module enforcement',
                'Enhanced security module verification',
            ],
        ],
    ],
];
