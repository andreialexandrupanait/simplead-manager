<?php

namespace App\Models;

use App\Jobs\CheckDomainExpiry;
use App\Jobs\CheckSslCertificate;
use App\Jobs\FetchSiteFavicon;
use App\Services\ModuleConfigService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Site extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        "name",
        "url",
        "user_id",
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
        "has_woocommerce",
        "favicon_path",
        "screenshot_path",
        "applied_preset_id",
        "is_preset_customized",
    ];

    protected $casts = [
        "is_multisite" => "boolean",
        "is_up" => "boolean",
        "ssl_ok" => "boolean",
        "backup_ok" => "boolean",
        "is_connected" => "boolean",
        "has_woocommerce" => "boolean",
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
        "applied_preset_id" => "integer",
        "is_preset_customized" => "boolean",
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

            // Fetch favicon
            FetchSiteFavicon::dispatch($site);

            // Apply preset via ModuleConfigService (creates uptime, backup, performance, security monitors etc.)
            $preset = $site->applied_preset_id
                ? SitePreset::find($site->applied_preset_id)
                : SitePreset::getDefault();

            if ($preset) {
                app(ModuleConfigService::class)->applyPreset($site, $preset);
            }
        });
    }

    private static array $twoPartTlds = [
        'co.uk', 'org.uk', 'me.uk', 'net.uk', 'ac.uk',
        'co.au', 'com.au', 'net.au', 'org.au',
        'co.nz', 'net.nz', 'org.nz',
        'co.za', 'org.za', 'web.za',
        'co.in', 'net.in', 'org.in',
        'com.br', 'net.br', 'org.br',
        'co.jp', 'or.jp', 'ne.jp',
        'co.kr', 'or.kr',
        'com.cn', 'net.cn', 'org.cn',
        'com.ro', 'org.ro', 'nom.ro',
        'co.il', 'org.il',
        'com.mx', 'org.mx',
        'com.ar', 'org.ar',
        'com.sg', 'org.sg',
        'com.hk', 'org.hk',
        'co.id', 'or.id',
        'com.my', 'org.my',
        'com.ph', 'org.ph',
        'com.tw', 'org.tw',
        'com.tr', 'org.tr',
        'co.th', 'or.th',
    ];

    public static function extractRootDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;

        // Remove www prefix
        $host = preg_replace('/^www\./', '', $host);

        $parts = explode('.', $host);

        if (count($parts) > 2) {
            $lastTwo = implode('.', array_slice($parts, -2));
            if (in_array($lastTwo, static::$twoPartTlds)) {
                $parts = array_slice($parts, -3);
            } else {
                $parts = array_slice($parts, -2);
            }
        }

        return implode('.', $parts);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appliedPreset(): BelongsTo
    {
        return $this->belongsTo(SitePreset::class, 'applied_preset_id');
    }

    public function securityMonitor(): HasOne
    {
        return $this->hasOne(SecurityMonitor::class);
    }

    public function databaseCleanupConfig(): HasOne
    {
        return $this->hasOne(DatabaseCleanupConfig::class);
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

    public function rollbackPoints(): HasMany
    {
        return $this->hasMany(RollbackPoint::class);
    }

    public function safeUpdates(): HasMany
    {
        return $this->hasMany(SafeUpdate::class);
    }

    public function resourceChecks(): HasMany
    {
        return $this->hasMany(ResourceCheck::class);
    }

    public function latestResourceCheck(): HasOne
    {
        return $this->hasOne(ResourceCheck::class)->latestOfMany('checked_at');
    }

    public function seoChecks(): HasMany
    {
        return $this->hasMany(SeoCheck::class);
    }

    public function latestSeoCheck(): HasOne
    {
        return $this->hasOne(SeoCheck::class)->latestOfMany('checked_at');
    }

    public function wooCommerceStats(): HasMany
    {
        return $this->hasMany(WooCommerceStat::class);
    }

    public function wooCommerceAlerts(): HasMany
    {
        return $this->hasMany(WooCommerceAlert::class);
    }

    public function trackedKeywords(): HasMany
    {
        return $this->hasMany(TrackedKeyword::class);
    }

    // Query Scopes

    public function scopeHealthy($query)
    {
        return $query->where('health_score', '>=', 90)->where('is_up', true);
    }

    public function scopeWarning($query)
    {
        return $query->whereBetween('health_score', [70, 89])->where('is_up', true);
    }

    public function scopeCritical($query)
    {
        return $query->where(function ($q) {
            $q->where('health_score', '<', 70)->orWhere('is_up', false);
        });
    }

    public function scopeSearchable($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('url', 'like', "%{$term}%");
        });
    }

    public function scopeConnected($query)
    {
        return $query->where('is_connected', true);
    }

    public function scopeWithPendingUpdates($query)
    {
        return $query->where('pending_updates_count', '>', 0);
    }

    // Accessors

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

    public function getFaviconUrlAttribute(): ?string
    {
        return $this->favicon_path ? Storage::disk('public')->url($this->favicon_path) : null;
    }

    public function getScreenshotUrlAttribute(): ?string
    {
        return $this->screenshot_path ? Storage::disk('public')->url($this->screenshot_path) : null;
    }
}
