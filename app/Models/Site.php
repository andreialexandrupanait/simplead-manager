<?php

namespace App\Models;

use App\Enums\HealthLevel;
use App\Jobs\CheckDomainExpiry;
use App\Jobs\CheckSslCertificate;
use App\Jobs\FetchSiteFavicon;
use App\Models\Traits\HasDomainExtraction;
use App\Models\Traits\HasSiteRelationships;
use App\Models\Traits\HasSiteScopes;
use App\Services\ModuleConfigService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Site extends Model
{
    use HasFactory, SoftDeletes;
    use HasSiteRelationships, HasSiteScopes, HasDomainExtraction;

    protected $fillable = [
        "name",
        "url",
        "user_id",
        "client_id",
        "status",
        "site_status_id",
        "sort_order",
        "health_score",
        "security_hardening_score",
        "custom_login_slug",
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
        "favicon_path",
        "screenshot_path",
        "applied_preset_id",
        "is_preset_customized",
        "backup_capabilities",
        "backup_capabilities_checked_at",
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
        "security_hardening_score" => "integer",
        "pending_updates_count" => "integer",
        "uptime_percentage" => "decimal:2",
        "db_size_mb" => "decimal:2",
        "uploads_size_mb" => "decimal:2",
        "api_key" => "encrypted",
        "api_secret" => "encrypted",
        "applied_preset_id" => "integer",
        "is_preset_customized" => "boolean",
        "backup_capabilities" => "array",
        "backup_capabilities_checked_at" => "datetime",
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
                ? SitePreset::with('presetModules')->find($site->applied_preset_id)
                : SitePreset::with('presetModules')->where('is_default', true)->first();

            if ($preset) {
                app(ModuleConfigService::class)->applyPreset($site, $preset);
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
