<?php

namespace App\Models;

use App\Jobs\CheckDomainExpiry;
use App\Jobs\CheckSslCertificate;
use App\Jobs\CheckUptime;
use App\Jobs\RunPerformanceTest;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        "name",
        "url",
        "client_id",
        "status",
        "site_status_id",
        "sort_order",
        "health_score",
        "type",
        "api_key",
        "api_secret",
        "api_endpoint",
        "is_connected",
        "last_synced_at",
        "wp_version",
        "php_version",
        "server_software",
        "is_multisite",
        "uptime_percentage",
        "is_up",
        "ssl_ok",
        "ssl_expiry",
        "pending_updates_count",
        "backup_ok",
        "last_backup_at",
        "notes",
        "db_size_mb",
        "uploads_size_mb",
        "core_update_version",
    ];

    protected $casts = [
        "is_multisite" => "boolean",
        "is_up" => "boolean",
        "ssl_ok" => "boolean",
        "backup_ok" => "boolean",
        "is_connected" => "boolean",
        "ssl_expiry" => "date",
        "last_backup_at" => "datetime",
        "last_synced_at" => "datetime",
        "sort_order" => "integer",
        "health_score" => "integer",
        "pending_updates_count" => "integer",
        "uptime_percentage" => "decimal:2",
        "db_size_mb" => "decimal:2",
        "uploads_size_mb" => "decimal:2",
        "api_key" => "encrypted",
        "api_secret" => "encrypted",
    ];

    protected static function booted(): void
    {
        static::creating(function (Site $site) {
            if (!$site->sort_order) {
                $site->sort_order = (static::max('sort_order') ?? 0) + 1;
            }
        });

        static::created(function (Site $site) {
            // Create SSL certificate monitor if site uses HTTPS
            if (str_starts_with($site->url, 'https://')) {
                $certificate = $site->sslCertificate()->create([
                    'domain' => parse_url($site->url, PHP_URL_HOST),
                ]);
                CheckSslCertificate::dispatch($certificate);
            }

            // Always create domain monitor
            $rootDomain = static::extractRootDomain($site->url);
            $parts = explode('.', $rootDomain);
            $tld = end($parts);

            $domainMonitor = $site->domainMonitor()->create([
                'domain' => $rootDomain,
                'tld' => $tld,
            ]);
            CheckDomainExpiry::dispatch($domainMonitor);

            // Create performance monitor
            $performanceMonitor = $site->performanceMonitor()->create([
                'is_active' => true,
                'frequency' => 'daily',
                'test_time' => '04:00',
            ]);
            RunPerformanceTest::dispatch($performanceMonitor, 'both');

            // Create link monitor
            $site->linkMonitor()->create([
                'is_active' => true,
                'frequency' => 'weekly',
                'scan_time' => '02:00',
                'day_of_week' => 0,
                'next_scan_at' => now()->next('Sunday')->setTimeFromTimeString('02:00'),
            ]);

            // Create uptime monitor
            $uptimeMonitor = $site->uptimeMonitor()->create([
                'url' => $site->url,
            ]);
            CheckUptime::dispatch($uptimeMonitor);
        });
    }

    public static function extractRootDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;

        // Remove www prefix
        $host = preg_replace('/^www\./', '', $host);

        // Take last 2 parts (handles example.com, sub.example.com)
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            $parts = array_slice($parts, -2);
        }

        return implode('.', $parts);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function siteStatus(): BelongsTo
    {
        return $this->belongsTo(SiteStatus::class);
    }

    public function uptimeMonitor(): HasOne
    {
        return $this->hasOne(UptimeMonitor::class);
    }

    public function sslCertificate(): HasOne
    {
        return $this->hasOne(SslCertificate::class);
    }

    public function domainMonitor(): HasOne
    {
        return $this->hasOne(DomainMonitor::class);
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

    public function linkMonitor(): HasOne
    {
        return $this->hasOne(LinkMonitor::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    public function latestCompletedBackup(): HasOne
    {
        return $this->hasOne(Backup::class)->where('status', 'completed')->latestOfMany('completed_at');
    }

    public function analyticsConnection(): HasOne
    {
        return $this->hasOne(AnalyticsConnection::class);
    }

    public function searchConsoleConnection(): HasOne
    {
        return $this->hasOne(SearchConsoleConnection::class);
    }

    public function reportSchedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    public function activeMaintenanceWindow(): HasOne
    {
        return $this->hasOne(MaintenanceWindow::class)->where('status', 'active');
    }

    public function dnsRecordCache(): HasOne
    {
        return $this->hasOne(DnsRecordCache::class);
    }

    public function coreFileChecks(): HasMany
    {
        return $this->hasMany(CoreFileCheck::class);
    }

    public function latestCoreFileCheck(): HasOne
    {
        return $this->hasOne(CoreFileCheck::class)->latestOfMany('checked_at');
    }

    public function sitePluginConflicts(): HasMany
    {
        return $this->hasMany(SitePluginConflict::class);
    }

    public function siteCronJobs(): HasMany
    {
        return $this->hasMany(SiteCronJob::class);
    }

    public function databaseCleanups(): HasMany
    {
        return $this->hasMany(DatabaseCleanup::class);
    }

    public function errorLogs(): HasMany
    {
        return $this->hasMany(ErrorLog::class);
    }

    public function databaseHealthChecks(): HasMany
    {
        return $this->hasMany(DatabaseHealthCheck::class);
    }

    public function latestDatabaseHealthCheck(): HasOne
    {
        return $this->hasOne(DatabaseHealthCheck::class)->latestOfMany('checked_at');
    }

    public function emailHealthChecks(): HasMany
    {
        return $this->hasMany(EmailHealthCheck::class);
    }

    public function latestEmailHealthCheck(): HasOne
    {
        return $this->hasOne(EmailHealthCheck::class)->latestOfMany('checked_at');
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

    public function wpAuditLogs(): HasMany
    {
        return $this->hasMany(WpAuditLog::class);
    }

    public function ipRules(): HasMany
    {
        return $this->hasMany(IpRule::class);
    }

    public function siteCloudflare(): HasOne
    {
        return $this->hasOne(SiteCloudflare::class);
    }

    public function getDomainAttribute(): string
    {
        return parse_url($this->url, PHP_URL_HOST) ?? $this->url;
    }

    public function getOverallStatusAttribute(): string
    {
        if (!$this->is_up) return "critical";
        if ($this->health_score === null) return "unknown";
        if ($this->health_score >= 90) return "healthy";
        if ($this->health_score >= 70) return "warning";
        return "critical";
    }
}
