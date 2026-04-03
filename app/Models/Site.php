<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HealthLevel;
use App\Jobs\FetchSiteFavicon;
use App\Models\Traits\HasDomainExtraction;
use App\Models\Traits\HasSiteRelationships;
use App\Models\Traits\HasSiteScopes;
use App\Services\DashboardService;
use App\Services\ModuleConfigService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $client_id
 * @property int|null $site_status_id
 * @property int $sort_order
 * @property string $name
 * @property string $url
 * @property string $status
 * @property int|null $health_score
 * @property int|null $security_hardening_score
 * @property string|null $custom_login_slug
 * @property string $type
 * @property string|null $api_key
 * @property string|null $api_secret
 * @property string|null $api_endpoint
 * @property bool $is_connected
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property string|null $wp_version
 * @property string|null $php_version
 * @property string|null $server_software
 * @property bool $is_multisite
 * @property float|null $uptime_percentage
 * @property bool $is_up
 * @property int $pending_updates_count
 * @property string|null $connector_version
 * @property bool $backup_ok
 * @property \Illuminate\Support\Carbon|null $last_backup_at
 * @property string|null $notes
 * @property float|null $db_size_mb
 * @property float|null $uploads_size_mb
 * @property string|null $core_update_version
 * @property string|null $favicon_path
 * @property string|null $screenshot_path
 * @property int|null $maintenance_plan_id
 * @property bool $is_plan_customized
 * @property int|null $report_template_id
 * @property array|null $backup_capabilities
 * @property \Illuminate\Support\Carbon|null $backup_capabilities_checked_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Client|null $client
 * @property-read \App\Models\SiteStatus|null $siteStatus
 * @property-read \App\Models\MaintenancePlan|null $maintenancePlan
 * @property-read \App\Models\UptimeMonitor|null $uptimeMonitor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SitePlugin> $sitePlugins
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SiteTheme> $siteThemes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SiteUser> $siteUsers
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UpdateLog> $updateLogs
 * @property-read \App\Models\BackupConfig|null $backupConfig
 * @property-read \App\Models\PerformanceMonitor|null $performanceMonitor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Backup> $backups
 * @property-read \App\Models\Backup|null $latestCompletedBackup
 * @property-read \App\Models\AnalyticsConnection|null $analyticsConnection
 * @property-read \App\Models\SearchConsoleConnection|null $searchConsoleConnection
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AnalyticsCache> $analyticsCaches
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SearchConsoleCache> $searchConsoleCaches
 * @property-read \App\Models\ReportTemplate|null $reportTemplate
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReportSchedule> $reportSchedules
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Report> $reports
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReportRecommendation> $reportRecommendations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ActivityLog> $activityLogs
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CoreFileCheck> $coreFileChecks
 * @property-read \App\Models\CoreFileCheck|null $latestCoreFileCheck
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SitePluginConflict> $sitePluginConflicts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\DatabaseCleanup> $databaseCleanups
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\DatabaseHealthCheck> $databaseHealthChecks
 * @property-read \App\Models\DatabaseHealthCheck|null $latestDatabaseHealthCheck
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EmailHealthCheck> $emailHealthChecks
 * @property-read \App\Models\EmailHealthCheck|null $latestEmailHealthCheck
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SecurityScan> $securityScans
 * @property-read \App\Models\SecurityScan|null $latestSecurityScan
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SecurityIssue> $securityIssues
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SecurityRecommendation> $securityRecommendations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\VulnerabilityAlert> $vulnerabilityAlerts
 * @property-read \App\Models\SiteCloudflare|null $siteCloudflare
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RollbackPoint> $rollbackPoints
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SafeUpdate> $safeUpdates
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\TrackedKeyword> $trackedKeywords
 * @property-read \App\Models\SecurityMonitor|null $securityMonitor
 * @property-read \App\Models\DatabaseCleanupConfig|null $databaseCleanupConfig
 * @property-read \App\Models\SiteHealthState|null $healthState
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SiteMonthlySnapshot> $monthlySnapshots
 * @property-read \App\Models\SiteReportConfig|null $reportConfig
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SecuritySetting> $securitySettings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SecurityCommand> $securityCommands
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SecurityPreset> $securityPresets
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SecurityActivityLog> $securityActivityLogs
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SecurityIpList> $securityIpLists
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SecurityBannedIp> $securityBannedIps
 * @property int|null $wp_admin_user_id
 * @property-read \App\Models\SiteUser|null $wpAdminUser
 */
class Site extends Model
{
    use HasDomainExtraction, HasSiteRelationships, HasSiteScopes;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'url',
        'user_id',
        'client_id',
        'status',
        'site_status_id',
        'sort_order',
        'health_score',
        'security_hardening_score',
        'custom_login_slug',
        'type',
        'api_key',
        'api_secret',
        'api_endpoint',
        'is_connected',
        'last_synced_at',
        'wp_version',
        'php_version',
        'server_software',
        'is_multisite',
        'uptime_percentage',
        'is_up',
        'pending_updates_count',
        'connector_version',
        'backup_ok',
        'last_backup_at',
        'notes',
        'db_size_mb',
        'uploads_size_mb',
        'core_update_version',
        'favicon_path',
        'screenshot_path',
        'maintenance_plan_id',
        'is_plan_customized',
        'report_template_id',
        'backup_capabilities',
        'backup_capabilities_checked_at',
        'wp_admin_user_id',
    ];

    protected $casts = [
        'is_multisite' => 'boolean',
        'is_up' => 'boolean',
        'backup_ok' => 'boolean',
        'is_connected' => 'boolean',
        'last_backup_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'sort_order' => 'integer',
        'health_score' => 'integer',
        'security_hardening_score' => 'integer',
        'pending_updates_count' => 'integer',
        'uptime_percentage' => 'decimal:2',
        'db_size_mb' => 'decimal:2',
        'uploads_size_mb' => 'decimal:2',
        'api_key' => 'encrypted',
        'api_secret' => 'encrypted',
        'maintenance_plan_id' => 'integer',
        'is_plan_customized' => 'boolean',
        'backup_capabilities' => 'array',
        'backup_capabilities_checked_at' => 'datetime',
        'wp_admin_user_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Site $site) {
            if (! $site->sort_order) {
                $site->sort_order = (static::max('sort_order') ?? 0) + 1;
            }
        });

        static::saved(function () {
            DashboardService::invalidateCache();
        });

        static::deleted(function () {
            DashboardService::invalidateCache();
        });

        static::created(function (Site $site) {
            // Fetch favicon
            FetchSiteFavicon::dispatch($site);

            // Apply plan via ModuleConfigService (creates uptime, backup, performance, security monitors etc.)
            $plan = $site->maintenance_plan_id
                ? MaintenancePlan::with('planModules')->find($site->maintenance_plan_id)
                : MaintenancePlan::with('planModules')->where('is_default', true)->first();

            if ($plan) {
                app(ModuleConfigService::class)->applyPlan($site, $plan);
            }
        });
    }

    // Accessors

    public function getDomainAttribute(): string
    {
        return parse_url($this->url, PHP_URL_HOST) ?? $this->url;
    }

    public function getOverallStatusAttribute(): string
    {
        return HealthLevel::fromScore($this->health_score, $this->is_up)->value;
    }

    public function getFaviconUrlAttribute(): ?string
    {
        return $this->favicon_path ? Storage::disk('public')->url($this->favicon_path) : null;
    }

    public function getScreenshotUrlAttribute(): ?string
    {
        return $this->screenshot_path ? Storage::disk('public')->url($this->screenshot_path) : null;
    }
}
