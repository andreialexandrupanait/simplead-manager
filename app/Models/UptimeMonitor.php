<?php

namespace App\Models;

use App\Enums\MonitorState;
use App\Enums\MonitorStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UptimeMonitor extends Model
{
    use HasFactory;

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
        'check_ssl',
        'ssl_expiry_threshold',
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
    ];

    protected $casts = [
        'status' => MonitorStatus::class,
        'current_state' => MonitorState::class,
        'http_headers' => 'array',
        'accepted_status_codes' => 'array',
        'alert_contacts' => 'array',
        'follow_redirects' => 'boolean',
        'keyword_case_sensitive' => 'boolean',
        'check_ssl' => 'boolean',
        'auth_password' => 'encrypted',
        'auth_token' => 'encrypted',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'last_state_change_at' => 'datetime',
        'timeout' => 'integer',
        'alert_after_failures' => 'integer',
        'consecutive_failures' => 'integer',
        'ssl_expiry_threshold' => 'integer',
        'avg_response_time' => 'integer',
        'last_response_time' => 'integer',
        'interval_minutes' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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
