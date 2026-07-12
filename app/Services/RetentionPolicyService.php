<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RetentionPolicyService
{
    public const CATEGORIES = [
        'uptime' => [
            'label' => 'Uptime Checks',
            'default' => 45,
            'min' => 7,
            'max' => 365,
            'tables' => [
                ['table' => 'uptime_checks', 'column' => 'checked_at', 'col_type' => 'timestamp', 'label' => 'Uptime checks', 'condition' => null],
            ],
        ],
        'performance' => [
            'label' => 'Performance Tests',
            'default' => 60,
            'min' => 14,
            'max' => 365,
            'tables' => [
                ['table' => 'performance_tests', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'Performance tests', 'condition' => null],
            ],
        ],
        'security' => [
            'label' => 'Security Scans',
            'default' => 90,
            'min' => 30,
            'max' => 730,
            'tables' => [
                ['table' => 'security_scans', 'column' => 'scanned_at', 'col_type' => 'timestamp', 'label' => 'Security scans', 'condition' => null],
                ['table' => 'core_file_checks', 'column' => 'checked_at', 'col_type' => 'timestamp', 'label' => 'Core file checks', 'condition' => null],
            ],
        ],
        'analytics' => [
            'label' => 'Analytics & Search Data',
            'default' => 60,
            'min' => 14,
            'max' => 365,
            'tables' => [
                ['table' => 'analytics_cache', 'column' => 'fetched_at', 'col_type' => 'timestamp', 'label' => 'Analytics cache', 'condition' => null],
                ['table' => 'search_console_cache', 'column' => 'fetched_at', 'col_type' => 'timestamp', 'label' => 'Search console cache', 'condition' => null],
                ['table' => 'keyword_positions', 'column' => 'date', 'col_type' => 'date', 'label' => 'Keyword positions', 'condition' => null],
            ],
        ],
        'activity_logs' => [
            'label' => 'Activity Logs',
            'default' => 180,
            'min' => 30,
            'max' => 730,
            'tables' => [
                ['table' => 'activity_logs', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'Activity logs', 'condition' => null],
            ],
        ],
        'notification_logs' => [
            'label' => 'Notification Logs',
            'default' => 90,
            'min' => 14,
            'max' => 365,
            'tables' => [
                ['table' => 'notification_logs', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'Notification logs', 'condition' => null],
            ],
        ],
        'system' => [
            'label' => 'System & Maintenance',
            'default' => 90,
            'min' => 14,
            'max' => 365,
            'tables' => [
                ['table' => 'database_health_checks', 'column' => 'checked_at', 'col_type' => 'timestamp', 'label' => 'Database health checks', 'condition' => null],
                ['table' => 'database_cleanups', 'column' => 'cleaned_at', 'col_type' => 'timestamp', 'label' => 'Database cleanups', 'condition' => null],
                ['table' => 'cloudflare_cache_purges', 'column' => 'purged_at', 'col_type' => 'timestamp', 'label' => 'Cloudflare cache purges', 'condition' => null],
                ['table' => 'update_logs', 'column' => 'performed_at', 'col_type' => 'timestamp', 'label' => 'Update logs', 'condition' => null],
                ['table' => 'safe_updates', 'column' => 'completed_at', 'col_type' => 'timestamp', 'label' => 'Safe updates', 'condition' => ['status', 'in', ['completed', 'failed']]],
            ],
        ],
        'security_hardening' => [
            'label' => 'Security Hardening',
            'default' => 90,
            'min' => 30,
            'max' => 365,
            'tables' => [
                ['table' => 'security_activity_logs', 'column' => 'occurred_at', 'col_type' => 'timestamp', 'label' => 'Security activity logs', 'condition' => null],
                ['table' => 'security_banned_ips', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'Security banned IPs', 'condition' => null],
            ],
        ],
        'failed_jobs' => [
            'label' => 'Failed Jobs',
            'default' => 7,
            'min' => 3,
            'max' => 90,
            'tables' => [
                ['table' => 'failed_jobs', 'column' => 'failed_at', 'col_type' => 'timestamp', 'label' => 'Failed jobs', 'condition' => null],
            ],
        ],
        'seo' => [
            'label' => 'SEO Audits',
            'default' => 90,
            'min' => 30,
            'max' => 365,
            'tables' => [
                ['table' => 'seo_audits', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'SEO audits', 'condition' => ['status', '=', 'completed']],
            ],
        ],
        // P2-43: previously-unpruned, growing (jsonb-heavy) tables. Gated behind
        // config('backups.retention_dry_run'): while the flag is on, the nightly
        // job LOGS how many rows it *would* prune without deleting anything, so
        // the owner can verify volumes before flipping the flag off.
        'dns_history' => [
            'label' => 'DNS Change History',
            'default' => 90,
            'min' => 30,
            'max' => 365,
            'dry_run' => true,
            'tables' => [
                ['table' => 'dns_changes', 'column' => 'detected_at', 'col_type' => 'timestamp', 'label' => 'DNS changes', 'condition' => null],
            ],
        ],
        'php_error_logs' => [
            'label' => 'PHP Error Logs',
            'default' => 60,
            'min' => 14,
            'max' => 365,
            'dry_run' => true,
            'tables' => [
                ['table' => 'php_error_logs', 'column' => 'last_seen_at', 'col_type' => 'timestamp', 'label' => 'PHP error logs', 'condition' => null],
            ],
        ],
        'in_app_notifications' => [
            'label' => 'In-App Notifications',
            'default' => 60,
            'min' => 14,
            'max' => 365,
            'dry_run' => true,
            'tables' => [
                ['table' => 'in_app_notifications', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'In-app notifications', 'condition' => null],
            ],
        ],
        'incident_responses' => [
            'label' => 'Incident Responses',
            'default' => 180,
            'min' => 30,
            'max' => 730,
            'dry_run' => true,
            'tables' => [
                // Only prune terminal incidents; an in-flight response must never
                // be deleted out from under the runner.
                ['table' => 'incident_responses', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'Incident responses', 'condition' => ['status', 'in', ['resolved', 'failed', 'escalated']]],
            ],
        ],
    ];

    public function __construct(
        protected SettingsService $settings,
    ) {}

    public function getDays(string $category): int
    {
        $config = self::CATEGORIES[$category] ?? null;
        if (! $config) {
            return 90;
        }

        $days = (int) $this->settings->get("retention_{$category}", $config['default']);

        return max($config['min'], min($config['max'], $days));
    }

    public function getDaysFresh(string $category): int
    {
        $config = self::CATEGORIES[$category] ?? null;
        if (! $config) {
            return 90;
        }

        $setting = AppSetting::where('key', "retention_{$category}")->first();
        $days = $setting ? (int) $setting->value : $config['default'];

        return max($config['min'], min($config['max'], $days));
    }

    public function setDays(string $category, int $days): void
    {
        $config = self::CATEGORIES[$category] ?? null;
        if (! $config) {
            return;
        }

        $days = max($config['min'], min($config['max'], $days));

        $this->settings->set("retention_{$category}", $days, 'retention', 'integer');
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('retention_enabled', true);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->set('retention_enabled', $enabled, 'retention', 'boolean');
    }

    public function getAll(): array
    {
        $result = [];

        foreach (self::CATEGORIES as $key => $config) {
            $result[$key] = [
                'label' => $config['label'],
                'days' => $this->getDays($key),
                'default' => $config['default'],
                'min' => $config['min'],
                'max' => $config['max'],
                'tables' => array_column($config['tables'], 'label'),
            ];
        }

        return $result;
    }

    public function getCategoryStats(string $category): array
    {
        $config = self::CATEGORIES[$category] ?? null;
        if (! $config) {
            return [];
        }

        $stats = [];

        foreach ($config['tables'] as $tableConfig) {
            $table = $tableConfig['table'];

            // Isolate per-table lookups: a table dropped by a migration (but
            // still lingering in a stale config) must not blow up the whole
            // Settings retention stats panel — skip it gracefully instead.
            try {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $estimate = DB::selectOne(
                    'SELECT reltuples::bigint AS estimate FROM pg_class WHERE relname = ?',
                    [$table]
                );

                $oldest = DB::selectOne(
                    "SELECT MIN(\"{$tableConfig['column']}\") AS oldest FROM \"{$table}\""
                );

                $stats[] = [
                    'table' => $table,
                    'label' => $tableConfig['label'],
                    'total_estimate' => $estimate->estimate ?? 0,
                    'oldest' => $oldest->oldest ?? null,
                ];
            } catch (\Throwable $e) {
                Log::warning("Retention stats lookup failed for table {$table}", [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    public function getLastRunResult(): ?array
    {
        return $this->settings->get('retention_last_run_result');
    }

    public function getLastRunAt(): ?string
    {
        return $this->settings->get('retention_last_run_at');
    }
}
