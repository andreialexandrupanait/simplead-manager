<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\DB;
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
                ['table' => 'uptime_checks', 'column' => 'checked_at', 'col_type' => 'timestamp', 'label' => 'Uptime checks', 'condition' => null, 'hasTable' => false],
            ],
        ],
        'performance' => [
            'label' => 'Performance Tests',
            'default' => 60,
            'min' => 14,
            'max' => 365,
            'tables' => [
                ['table' => 'performance_tests', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'Performance tests', 'condition' => null, 'hasTable' => false],
            ],
        ],
        'security' => [
            'label' => 'Security Scans',
            'default' => 90,
            'min' => 30,
            'max' => 730,
            'tables' => [
                ['table' => 'security_scans', 'column' => 'scanned_at', 'col_type' => 'timestamp', 'label' => 'Security scans', 'condition' => null, 'hasTable' => false],
                ['table' => 'core_file_checks', 'column' => 'checked_at', 'col_type' => 'timestamp', 'label' => 'Core file checks', 'condition' => null, 'hasTable' => false],
            ],
        ],
        'analytics' => [
            'label' => 'Analytics & Search Data',
            'default' => 60,
            'min' => 14,
            'max' => 365,
            'tables' => [
                ['table' => 'analytics_cache', 'column' => 'fetched_at', 'col_type' => 'timestamp', 'label' => 'Analytics cache', 'condition' => null, 'hasTable' => false],
                ['table' => 'search_console_cache', 'column' => 'fetched_at', 'col_type' => 'timestamp', 'label' => 'Search console cache', 'condition' => null, 'hasTable' => false],
                ['table' => 'keyword_positions', 'column' => 'date', 'col_type' => 'date', 'label' => 'Keyword positions', 'condition' => null, 'hasTable' => false],
            ],
        ],
        'ssl_domain' => [
            'label' => 'SSL & Domain Checks',
            'default' => 90,
            'min' => 30,
            'max' => 730,
            'tables' => [
                ['table' => 'ssl_check_history', 'column' => 'checked_at', 'col_type' => 'timestamp', 'label' => 'SSL check history', 'condition' => null, 'hasTable' => false],
            ],
        ],
        'activity_logs' => [
            'label' => 'Activity Logs',
            'default' => 180,
            'min' => 30,
            'max' => 730,
            'tables' => [
                ['table' => 'activity_logs', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'Activity logs', 'condition' => null, 'hasTable' => false],
            ],
        ],
        'notification_logs' => [
            'label' => 'Notification Logs',
            'default' => 90,
            'min' => 14,
            'max' => 365,
            'tables' => [
                ['table' => 'notification_logs', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'Notification logs', 'condition' => null, 'hasTable' => true],
            ],
        ],
        'system' => [
            'label' => 'System & Maintenance',
            'default' => 90,
            'min' => 14,
            'max' => 365,
            'tables' => [
                ['table' => 'database_health_checks', 'column' => 'checked_at', 'col_type' => 'timestamp', 'label' => 'Database health checks', 'condition' => null, 'hasTable' => false],
                ['table' => 'email_health_checks', 'column' => 'checked_at', 'col_type' => 'timestamp', 'label' => 'Email health checks', 'condition' => null, 'hasTable' => false],
                ['table' => 'database_cleanups', 'column' => 'cleaned_at', 'col_type' => 'timestamp', 'label' => 'Database cleanups', 'condition' => null, 'hasTable' => false],
                ['table' => 'cloudflare_cache_purges', 'column' => 'purged_at', 'col_type' => 'timestamp', 'label' => 'Cloudflare cache purges', 'condition' => null, 'hasTable' => true],
                ['table' => 'update_logs', 'column' => 'performed_at', 'col_type' => 'timestamp', 'label' => 'Update logs', 'condition' => null, 'hasTable' => false],
                ['table' => 'safe_updates', 'column' => 'completed_at', 'col_type' => 'timestamp', 'label' => 'Safe updates', 'condition' => ['status', 'in', ['completed', 'failed']], 'hasTable' => false],
            ],
        ],
        'security_hardening' => [
            'label' => 'Security Hardening',
            'default' => 90,
            'min' => 30,
            'max' => 365,
            'tables' => [
                ['table' => 'security_activity_logs', 'column' => 'occurred_at', 'col_type' => 'timestamp', 'label' => 'Security activity logs', 'condition' => null, 'hasTable' => true],
                ['table' => 'security_commands', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'Security commands (completed)', 'condition' => ['status', 'in', ['completed', 'failed', 'cancelled']], 'hasTable' => true],
                ['table' => 'security_banned_ips', 'column' => 'created_at', 'col_type' => 'timestamp', 'label' => 'Security banned IPs', 'condition' => null, 'hasTable' => true],
            ],
        ],
        'failed_jobs' => [
            'label' => 'Failed Jobs',
            'default' => 7,
            'min' => 3,
            'max' => 90,
            'tables' => [
                ['table' => 'failed_jobs', 'column' => 'failed_at', 'col_type' => 'timestamp', 'label' => 'Failed jobs', 'condition' => null, 'hasTable' => false],
            ],
        ],
    ];

    public function __construct(
        protected SettingsService $settings,
    ) {}

    public function getDays(string $category): int
    {
        $config = self::CATEGORIES[$category] ?? null;
        if (!$config) {
            return 90;
        }

        $days = (int) $this->settings->get("retention_{$category}", $config['default']);

        return max($config['min'], min($config['max'], $days));
    }

    public function getDaysFresh(string $category): int
    {
        $config = self::CATEGORIES[$category] ?? null;
        if (!$config) {
            return 90;
        }

        $setting = AppSetting::where('key', "retention_{$category}")->first();
        $days = $setting ? (int) $setting->value : $config['default'];

        return max($config['min'], min($config['max'], $days));
    }

    public function setDays(string $category, int $days): void
    {
        $config = self::CATEGORIES[$category] ?? null;
        if (!$config) {
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
        if (!$config) {
            return [];
        }

        $stats = [];

        foreach ($config['tables'] as $tableConfig) {
            $table = $tableConfig['table'];
            if ($tableConfig['hasTable'] && !Schema::hasTable($table)) {
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
