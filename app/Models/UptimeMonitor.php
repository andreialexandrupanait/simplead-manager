<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MonitorState;
use App\Enums\MonitorStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $site_id
 * @property string $type
 * @property string $url
 * @property int $timeout
 * @property string $http_method
 * @property array|null $http_headers
 * @property string|null $http_body
 * @property array|null $accepted_status_codes
 * @property bool $follow_redirects
 * @property string|null $auth_type
 * @property string|null $auth_username
 * @property string|null $auth_password
 * @property string|null $auth_token
 * @property string|null $keyword
 * @property string|null $keyword_type
 * @property bool $keyword_case_sensitive
 * @property bool $check_ssl
 * @property int $ssl_expiry_threshold
 * @property int $alert_after_failures
 * @property array|null $alert_contacts
 * @property int $consecutive_failures
 * @property \App\Enums\MonitorStatus $status
 * @property \App\Enums\MonitorState $current_state
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property \Illuminate\Support\Carbon|null $next_check_at
 * @property \Illuminate\Support\Carbon|null $last_state_change_at
 * @property float|null $uptime_24h
 * @property float|null $uptime_7d
 * @property float|null $uptime_30d
 * @property float|null $uptime_365d
 * @property int|null $avg_response_time
 * @property int|null $last_response_time
 * @property string|null $last_failure_reason
 * @property int $interval_minutes
 * @property \Illuminate\Support\Carbon|null $maintenance_starts_at
 * @property \Illuminate\Support\Carbon|null $maintenance_ends_at
 * @property string|null $maintenance_reason
 * @property array|null $check_locations
 * @property bool $require_all_locations_down
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Site|null $site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, UptimeCheck> $checks
 * @property-read \Illuminate\Database\Eloquent\Collection<int, UptimeIncident> $incidents
 * @property-read UptimeCheck|null $latestCheck
 * @property-read UptimeIncident|null $ongoingIncident
 * @property-read int|null $up
 * @property-read int|null $down
 * @property-read int|null $degraded
 * @property-read int|null $paused
 * @property-read int|null $total
 */
class UptimeMonitor extends Model
{
    use HasFactory;

    /**
     * Available check locations. 'primary' is the main server.
     * External locations will be added as probe integrations are built.
     */
    public const LOCATIONS = [
        'primary' => ['label' => 'Primary (Server)', 'region' => 'EU'],
    ];

    protected $fillable = [
        'site_id',
        'type',
        'url',
        'timeout',
        'http_method',
        'http_headers',
        'http_body',
        'accepted_status_codes',
        'follow_redirects',
        'auth_type',
        'auth_username',
        'auth_password',
        'auth_token',
        'keyword',
        'keyword_type',
        'keyword_case_sensitive',
        'alert_after_failures',
        'alert_contacts',
        'consecutive_failures',
        'status',
        'current_state',
        'last_checked_at',
        'next_check_at',
        'last_state_change_at',
        'uptime_24h',
        'uptime_7d',
        'uptime_30d',
        'uptime_365d',
        'avg_response_time',
        'last_response_time',
        'last_failure_reason',
        'interval_minutes',
        'maintenance_starts_at',
        'maintenance_ends_at',
        'maintenance_reason',
        'check_locations',
        'require_all_locations_down',
    ];

    protected $casts = [
        'status' => MonitorStatus::class,
        'current_state' => MonitorState::class,
        'http_headers' => 'array',
        'accepted_status_codes' => 'array',
        'alert_contacts' => 'array',
        'follow_redirects' => 'boolean',
        'keyword_case_sensitive' => 'boolean',
        'auth_password' => 'encrypted',
        'auth_token' => 'encrypted',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'last_state_change_at' => 'datetime',
        'timeout' => 'integer',
        'alert_after_failures' => 'integer',
        'consecutive_failures' => 'integer',
        'avg_response_time' => 'integer',
        'last_response_time' => 'integer',
        'interval_minutes' => 'integer',
        'maintenance_starts_at' => 'datetime',
        'maintenance_ends_at' => 'datetime',
        'check_locations' => 'array',
        'require_all_locations_down' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isInMaintenanceWindow(): bool
    {
        if (! $this->maintenance_starts_at || ! $this->maintenance_ends_at) {
            return false;
        }

        return now()->between($this->maintenance_starts_at, $this->maintenance_ends_at);
    }

    public function clearMaintenanceWindow(): void
    {
        $this->update([
            'maintenance_starts_at' => null,
            'maintenance_ends_at' => null,
            'maintenance_reason' => null,
        ]);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(UptimeCheck::class, 'monitor_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(UptimeIncident::class, 'monitor_id');
    }

    public function latestCheck(): HasOne
    {
        return $this->hasOne(UptimeCheck::class, 'monitor_id')->latestOfMany('checked_at');
    }

    public function ongoingIncident(): HasOne
    {
        return $this->hasOne(UptimeIncident::class, 'monitor_id')->where('status', 'ongoing');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MonitorStatus::Active);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('next_check_at')
                ->orWhere('next_check_at', '<=', now());
        });
    }
}
