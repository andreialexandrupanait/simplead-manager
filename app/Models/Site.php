<?php

namespace App\Models;

use App\Enums\HealthLevel;
use App\Jobs\CheckSslCertificate;
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
        "connector_version",
        "backup_ok",
        "last_backup_at",
        "notes",
        "db_size_mb",
        "uploads_size_mb",
        "core_update_version",
        "favicon_path",
        "screenshot_path",
        "maintenance_plan_id",
        "is_plan_customized",
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
        "maintenance_plan_id" => "integer",
        "is_plan_customized" => "boolean",
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

        static::saved(function () {
            DashboardService::invalidateCache();
        });

        static::deleted(function () {
            DashboardService::invalidateCache();
        });

        static::created(function (Site $site) {
            // Create SSL certificate monitor if site uses HTTPS
            if (str_starts_with($site->url, 'https://')) {
                $certificate = $site->sslCertificate()->create([
                    'domain' => parse_url($site->url, PHP_URL_HOST),
                ]);
                CheckSslCertificate::dispatch($certificate);
            }

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
