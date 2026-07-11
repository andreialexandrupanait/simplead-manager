<?php

declare(strict_types=1);

return [
    'changelog' => [
        'unreleased' => [
            'changes' => [],
        ],
        '2.17.0' => [
            'date' => '2026-07-11',
            'changes' => [
                'Security: Two-factor authentication (email code) for interactive WP logins — role-targeted, 10-min codes, 5-attempt lockout, 30-day trusted devices, configurable fail-open/closed on mail failure',
                'Security: /security-settings accepts unban_ips — clears the ban option AND the brute-force transient so manager-side unbans actually apply',
                'Security: security-fix now handles disable_directory_listing (was advertised as fixable but always failed)',
                'Security: IP whitelist restricts login/wp-admin/XML-RPC only — a non-empty whitelist no longer serves 403s to public visitors (blocklist stays site-wide)',
                'Feature: Per-table management — optimize individual tables, convert MyISAM to InnoDB, delete non-core tables',
                'Feature: Plugin ownership detection — shows which plugin created each table and its status (active/inactive/not installed)',
                'Enhanced database health response with collation and core table detection',
                'Added multisite tables to core table protection list',
            ],
        ],
        '2.9.15' => [
            'date' => '2026-04-05',
            'changes' => [
                'Removed email tweaks (replaced by dedicated Postmark integration)',
                'Added content duplication, custom post ordering, and 7 other content & media tweaks',
            ],
        ],
        '2.9.14' => [
            'date' => '2026-04-03',
            'changes' => [
                'Feature: Batch bulk-delete endpoint for spam user removal (single API call instead of per-user)',
            ],
        ],
        '2.9.13' => [
            'date' => '2026-04-03',
            'changes' => [
                'Feature: Sync WooCommerce order counts per user for spam detection',
            ],
        ],
        '2.9.12' => [
            'date' => '2026-04-01',
            'changes' => [
                'Fix: Exempt backup endpoints from rate limiting (fixes stuck backups on large sites)',
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
