<?php

namespace App\Models;

use App\Jobs\CheckDomainExpiry;
use App\Jobs\CheckSslCertificate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
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
