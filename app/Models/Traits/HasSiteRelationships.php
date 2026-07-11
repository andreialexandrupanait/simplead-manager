<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Enums\BackupStatus;
use App\Models\ActivityLog;
use App\Models\AnalyticsCache;
use App\Models\AnalyticsConnection;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Client;
use App\Models\CoreFileCheck;
use App\Models\DatabaseCleanup;
use App\Models\DatabaseCleanupConfig;
use App\Models\DatabaseHealthCheck;
use App\Models\IncidentResponse;
use App\Models\MaintenancePlan;
use App\Models\PerformanceMonitor;
use App\Models\Report;
use App\Models\ReportRecommendation;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\RollbackPoint;
use App\Models\SafeUpdate;
use App\Models\SearchConsoleCache;
use App\Models\SearchConsoleConnection;
use App\Models\SecurityActivityLog;
use App\Models\SecurityBannedIp;
use App\Models\SecurityIpList;
use App\Models\SecurityIssue;
use App\Models\SecurityMonitor;
use App\Models\SecurityPreset;
use App\Models\SecurityRecommendation;
use App\Models\SecurityScan;
use App\Models\SecuritySetting;
use App\Models\SeoAudit;
use App\Models\SeoMonitor;
use App\Models\SiteCloudflare;
use App\Models\SiteHealthState;
use App\Models\SiteMonthlySnapshot;
use App\Models\SitePlugin;
use App\Models\SitePluginConflict;
use App\Models\SiteReportConfig;
use App\Models\SiteStatus;
use App\Models\SiteTheme;
use App\Models\SiteUser;
use App\Models\ThemeFileCheck;
use App\Models\UpdateLog;
use App\Models\UptimeMonitor;
use App\Models\User;
use App\Models\VulnerabilityAlert;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasSiteRelationships
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function maintenancePlan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Tag::class, 'site_tag');
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(\App\Models\SiteRedirect::class);
    }

    public function siteStatus(): BelongsTo
    {
        return $this->belongsTo(SiteStatus::class);
    }

    public function uptimeMonitor(): HasOne
    {
        return $this->hasOne(UptimeMonitor::class);
    }

    public function sitePlugins(): HasMany
    {
        return $this->hasMany(SitePlugin::class);
    }

    public function siteThemes(): HasMany
    {
        return $this->hasMany(SiteTheme::class);
    }

    public function siteUsers(): HasMany
    {
        return $this->hasMany(SiteUser::class);
    }

    public function wpAdminUser(): BelongsTo
    {
        return $this->belongsTo(SiteUser::class, 'wp_admin_user_id');
    }

    public function updateLogs(): HasMany
    {
        return $this->hasMany(UpdateLog::class);
    }

    public function backupConfig(): HasOne
    {
        return $this->hasOne(BackupConfig::class);
    }

    public function performanceMonitor(): HasOne
    {
        return $this->hasOne(PerformanceMonitor::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    public function latestCompletedBackup(): HasOne
    {
        return $this->hasOne(Backup::class)->where('status', BackupStatus::Completed)->latestOfMany('completed_at');
    }

    public function analyticsConnection(): HasOne
    {
        return $this->hasOne(AnalyticsConnection::class);
    }

    public function searchConsoleConnection(): HasOne
    {
        return $this->hasOne(SearchConsoleConnection::class);
    }

    public function analyticsCaches(): HasMany
    {
        return $this->hasMany(AnalyticsCache::class);
    }

    public function searchConsoleCaches(): HasMany
    {
        return $this->hasMany(SearchConsoleCache::class);
    }

    public function reportTemplate(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class);
    }

    public function reportSchedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function reportRecommendations(): HasMany
    {
        return $this->hasMany(ReportRecommendation::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function coreFileChecks(): HasMany
    {
        return $this->hasMany(CoreFileCheck::class);
    }

    public function latestCoreFileCheck(): HasOne
    {
        return $this->hasOne(CoreFileCheck::class)->latestOfMany('checked_at');
    }

    public function themeFileChecks(): HasMany
    {
        return $this->hasMany(ThemeFileCheck::class);
    }

    public function sitePluginConflicts(): HasMany
    {
        return $this->hasMany(SitePluginConflict::class);
    }

    public function databaseCleanups(): HasMany
    {
        return $this->hasMany(DatabaseCleanup::class);
    }

    public function databaseHealthChecks(): HasMany
    {
        return $this->hasMany(DatabaseHealthCheck::class);
    }

    public function latestDatabaseHealthCheck(): HasOne
    {
        return $this->hasOne(DatabaseHealthCheck::class)->latestOfMany('checked_at');
    }

    public function securityScans(): HasMany
    {
        return $this->hasMany(SecurityScan::class);
    }

    public function latestSecurityScan(): HasOne
    {
        return $this->hasOne(SecurityScan::class)->latestOfMany('scanned_at');
    }

    public function securityIssues(): HasMany
    {
        return $this->hasMany(SecurityIssue::class);
    }

    public function securityRecommendations(): HasMany
    {
        return $this->hasMany(SecurityRecommendation::class);
    }

    public function vulnerabilityAlerts(): HasMany
    {
        return $this->hasMany(VulnerabilityAlert::class);
    }

    public function siteCloudflare(): HasOne
    {
        return $this->hasOne(SiteCloudflare::class);
    }

    public function rollbackPoints(): HasMany
    {
        return $this->hasMany(RollbackPoint::class);
    }

    public function safeUpdates(): HasMany
    {
        return $this->hasMany(SafeUpdate::class);
    }

    public function securityMonitor(): HasOne
    {
        return $this->hasOne(SecurityMonitor::class);
    }

    public function databaseCleanupConfig(): HasOne
    {
        return $this->hasOne(DatabaseCleanupConfig::class);
    }

    public function dnsMonitor(): HasOne
    {
        return $this->hasOne(\App\Models\DnsMonitor::class);
    }

    public function healthState(): HasOne
    {
        return $this->hasOne(SiteHealthState::class);
    }

    public function monthlySnapshots(): HasMany
    {
        return $this->hasMany(SiteMonthlySnapshot::class);
    }

    public function reportConfig(): HasOne
    {
        return $this->hasOne(SiteReportConfig::class);
    }

    // Security Hardening relationships

    public function securitySettings(): HasMany
    {
        return $this->hasMany(SecuritySetting::class);
    }

    public function securityPresets(): BelongsToMany
    {
        return $this->belongsToMany(SecurityPreset::class, 'security_preset_site')
            ->withPivot('applied_at', 'applied_version');
    }

    public function securityActivityLogs(): HasMany
    {
        return $this->hasMany(SecurityActivityLog::class);
    }

    public function securityIpLists(): HasMany
    {
        return $this->hasMany(SecurityIpList::class);
    }

    public function securityBannedIps(): HasMany
    {
        return $this->hasMany(SecurityBannedIp::class);
    }

    public function incidentResponses(): HasMany
    {
        return $this->hasMany(IncidentResponse::class);
    }

    public function seoMonitor(): HasOne
    {
        return $this->hasOne(SeoMonitor::class);
    }

    public function seoAudits(): HasMany
    {
        return $this->hasMany(SeoAudit::class);
    }

    public function latestSeoAudit(): HasOne
    {
        return $this->hasOne(SeoAudit::class)->latestOfMany('scanned_at');
    }
}
