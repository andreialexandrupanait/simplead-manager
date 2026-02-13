<?php

namespace App\Services;

use App\Models\AnalyticsConnection;
use App\Models\BackupConfig;
use App\Models\DatabaseCleanupConfig;
use App\Models\PerformanceMonitor;
use App\Models\SearchConsoleConnection;
use App\Models\SecurityMonitor;
use App\Models\Site;
use App\Models\SiteCloudflare;
use App\Models\SitePreset;
use App\Models\SslCertificate;
use App\Models\UptimeMonitor;

class ModuleConfigService
{
    /**
     * Module key → domain table mapping with enabled/interval column info.
     */
    private const MODULE_MAP = [
        'uptime' => [
            'relation' => 'uptimeMonitor',
            'model' => UptimeMonitor::class,
            'enabled_column' => 'status', // 'active' or 'paused'
            'enabled_value' => 'active',
            'disabled_value' => 'paused',
            'interval_column' => 'interval_minutes',
        ],
        'backup' => [
            'relation' => 'backupConfig',
            'model' => BackupConfig::class,
            'enabled_column' => 'is_enabled',
            'interval_column' => null, // uses frequency
        ],
        'ssl' => [
            'relation' => 'sslCertificate',
            'model' => SslCertificate::class,
            'enabled_column' => 'alerts_enabled',
            'interval_column' => null,
        ],
        'performance' => [
            'relation' => 'performanceMonitor',
            'model' => PerformanceMonitor::class,
            'enabled_column' => 'is_active',
            'interval_column' => 'interval_minutes',
        ],
        'security' => [
            'relation' => 'securityMonitor',
            'model' => SecurityMonitor::class,
            'enabled_column' => 'is_active',
            'interval_column' => 'interval_minutes',
        ],
        'analytics' => [
            'relation' => 'analyticsConnection',
            'model' => AnalyticsConnection::class,
            'enabled_column' => 'is_active',
            'interval_column' => 'interval_minutes',
        ],
        'search_console' => [
            'relation' => 'searchConsoleConnection',
            'model' => SearchConsoleConnection::class,
            'enabled_column' => 'is_active',
            'interval_column' => 'interval_minutes',
        ],
        'cloudflare' => [
            'relation' => 'siteCloudflare',
            'model' => SiteCloudflare::class,
            'enabled_column' => 'is_active',
            'interval_column' => 'interval_minutes',
        ],
        'database_cleanup' => [
            'relation' => 'databaseCleanupConfig',
            'model' => DatabaseCleanupConfig::class,
            'enabled_column' => 'is_enabled',
            'interval_column' => null, // uses frequency
        ],
    ];

    /**
     * Minimum intervals in minutes (enforced in updateInterval).
     */
    private const MIN_INTERVALS = [
        'uptime' => 3,
        'ssl' => 360,       // 6h
        'backup' => 60,      // 1h
        'performance' => 360, // 6h
        'security' => 360,    // 6h
        'analytics' => 720,   // 12h
        'search_console' => 720, // 12h
        'cloudflare' => 360,  // 6h
        'database_cleanup' => 10080, // 7 days
    ];

    /**
     * Default intervals in minutes.
     */
    private const DEFAULT_INTERVALS = [
        'uptime' => 5,
        'ssl' => 720,         // 12h
        'backup' => 1440,     // daily
        'performance' => 10080, // 7 days
        'security' => 10080,   // 7 days
        'analytics' => 1440,   // 24h
        'search_console' => 1440, // 24h
        'cloudflare' => 360,   // 6h
        'database_cleanup' => 43200, // 30 days
    ];

    /**
     * Modules that require an external connection before they can operate.
     */
    private const CONNECTION_REQUIRED = ['analytics', 'search_console', 'cloudflare'];

    /**
     * Apply a preset to a site. Creates/updates domain table rows for each module.
     */
    public function applyPreset(Site $site, SitePreset $preset): void
    {
        $modules = $preset->modules;

        foreach (self::MODULE_MAP as $moduleKey => $config) {
            $enabled = $modules[$moduleKey]['enabled'] ?? false;
            $interval = $modules[$moduleKey]['interval'] ?? self::DEFAULT_INTERVALS[$moduleKey] ?? null;

            $this->configureModule($site, $moduleKey, $enabled, $interval);
        }

        $site->update([
            'applied_preset_id' => $preset->id,
            'is_preset_customized' => false,
        ]);
    }

    /**
     * Toggle a single module on or off.
     */
    public function toggleModule(Site $site, string $module, bool $enabled): void
    {
        $this->validateModuleKey($module);
        $config = self::MODULE_MAP[$module];

        $record = $site->{$config['relation']};

        if (!$record) {
            if ($enabled) {
                $this->createModuleRecord($site, $module, $enabled);
            }
            return;
        }

        if ($config['enabled_column'] === null) {
            return;
        }

        if ($config['enabled_column'] === 'status') {
            $record->update(['status' => $enabled ? $config['enabled_value'] : $config['disabled_value']]);
        } else {
            $record->update([$config['enabled_column'] => $enabled]);
        }

        $this->markPresetCustomized($site);
    }

    /**
     * Update the check interval for a module.
     */
    public function updateInterval(Site $site, string $module, int $minutes): void
    {
        $this->validateModuleKey($module);
        $config = self::MODULE_MAP[$module];

        if (!$config['interval_column']) {
            return; // Module doesn't support interval changes
        }

        // Enforce minimum
        $min = self::MIN_INTERVALS[$module] ?? 1;
        $minutes = max($minutes, $min);

        $record = $site->{$config['relation']};
        if ($record) {
            $record->update([$config['interval_column'] => $minutes]);
            $this->markPresetCustomized($site);
        }
    }

    /**
     * Get the full module configuration for a site.
     */
    public function getConfig(Site $site): array
    {
        $site->load(array_column(self::MODULE_MAP, 'relation'));

        $config = [];
        foreach (self::MODULE_MAP as $moduleKey => $map) {
            $record = $site->{$map['relation']};
            $config[$moduleKey] = [
                'exists' => $record !== null,
                'enabled' => $this->resolveEnabled($record, $map),
                'interval' => $record && $map['interval_column'] ? $record->{$map['interval_column']} : (self::DEFAULT_INTERVALS[$moduleKey] ?? null),
                'requires_connection' => in_array($moduleKey, self::CONNECTION_REQUIRED),
                'is_connected' => $record !== null && !in_array($moduleKey, self::CONNECTION_REQUIRED),
            ];

            // For connection-required modules, check if the external connection actually exists
            if (in_array($moduleKey, self::CONNECTION_REQUIRED)) {
                $config[$moduleKey]['is_connected'] = $record !== null;
            }
        }

        return $config;
    }

    /**
     * Check if a specific module is active for a site.
     */
    public function isModuleActive(Site $site, string $module): bool
    {
        $this->validateModuleKey($module);
        $config = self::MODULE_MAP[$module];

        $record = $site->{$config['relation']};
        if (!$record) {
            return false;
        }

        return $this->resolveEnabled($record, $config);
    }

    /**
     * Get available module keys.
     */
    public static function getModuleKeys(): array
    {
        return array_keys(self::MODULE_MAP);
    }

    /**
     * Get default intervals.
     */
    public static function getDefaultIntervals(): array
    {
        return self::DEFAULT_INTERVALS;
    }

    /**
     * Get minimum intervals.
     */
    public static function getMinIntervals(): array
    {
        return self::MIN_INTERVALS;
    }

    /**
     * Configure a single module for a site (create/update).
     */
    private function configureModule(Site $site, string $moduleKey, bool $enabled, ?int $interval = null): void
    {
        $config = self::MODULE_MAP[$moduleKey];
        $record = $site->{$config['relation']};

        if ($record) {
            $this->updateModuleRecord($record, $config, $enabled, $interval);
        } elseif ($enabled) {
            $this->createModuleRecord($site, $moduleKey, $enabled, $interval);
        }
    }

    /**
     * Create a new domain table record for a module.
     */
    private function createModuleRecord(Site $site, string $moduleKey, bool $enabled, ?int $interval = null): void
    {
        $config = self::MODULE_MAP[$moduleKey];
        $jitter = rand(0, (int) (($interval ?? self::DEFAULT_INTERVALS[$moduleKey] ?? 5) * 0.1));

        $data = ['site_id' => $site->id];

        switch ($moduleKey) {
            case 'uptime':
                $data['url'] = $site->url;
                $data['status'] = $enabled ? 'active' : 'paused';
                $data['interval_minutes'] = $interval ?? 5;
                $data['next_check_at'] = now()->addMinutes($jitter);
                break;

            case 'backup':
                $data['is_enabled'] = $enabled;
                $data['frequency'] = 'daily';
                $data['time'] = '03:00';
                $data['timezone'] = 'UTC';
                $data['type'] = 'full';
                $data['retention_type'] = 'count';
                $data['retention_value'] = 7;
                $data['next_backup_at'] = now()->addDay()->setTime(3, 0)->addMinutes($jitter);
                break;

            case 'performance':
                $data['is_active'] = $enabled;
                $data['interval_minutes'] = $interval ?? 10080;
                $data['frequency'] = 'daily';
                $data['test_time'] = '04:00';
                $data['next_test_at'] = now()->addDay()->setTime(4, 0)->addMinutes($jitter);
                break;

            case 'security':
                $data['is_active'] = $enabled;
                $data['interval_minutes'] = $interval ?? 10080;
                $data['next_scan_at'] = now()->addMinutes(rand(60, 1440));
                break;

            case 'database_cleanup':
                $data['is_enabled'] = $enabled;
                $data['frequency'] = 'monthly';
                $data['auto_clean_types'] = ['revisions', 'spam', 'trash', 'transients'];
                $data['next_cleanup_at'] = now()->addMonth()->startOfMonth()->addMinutes($jitter);
                break;

            default:
                // For analytics, search_console, cloudflare — these require external connections
                // We don't create records here; they're created when the user connects the service.
                return;
        }

        $config['model']::create($data);
    }

    /**
     * Update an existing domain table record.
     */
    private function updateModuleRecord($record, array $config, bool $enabled, ?int $interval = null): void
    {
        $updates = [];

        if ($config['enabled_column']) {
            if ($config['enabled_column'] === 'status') {
                $updates['status'] = $enabled ? ($config['enabled_value'] ?? 'active') : ($config['disabled_value'] ?? 'paused');
            } else {
                $updates[$config['enabled_column']] = $enabled;
            }
        }

        if ($interval && $config['interval_column']) {
            $min = self::MIN_INTERVALS[array_search($config, self::MODULE_MAP)] ?? 1;
            $updates[$config['interval_column']] = max($interval, $min);
        }

        if (!empty($updates)) {
            $record->update($updates);
        }
    }

    /**
     * Resolve whether a module record is "enabled".
     */
    private function resolveEnabled($record, array $config): bool
    {
        if (!$record || !$config['enabled_column']) {
            return $record !== null; // If no enabled column, existence = enabled
        }

        if ($config['enabled_column'] === 'status') {
            return $record->status === ($config['enabled_value'] ?? 'active');
        }

        return (bool) $record->{$config['enabled_column']};
    }

    /**
     * Mark a site's preset as customized.
     */
    private function markPresetCustomized(Site $site): void
    {
        if ($site->applied_preset_id && !$site->is_preset_customized) {
            $site->update(['is_preset_customized' => true]);
        }
    }

    private function validateModuleKey(string $module): void
    {
        if (!isset(self::MODULE_MAP[$module])) {
            throw new \InvalidArgumentException("Unknown module key: {$module}");
        }
    }
}
