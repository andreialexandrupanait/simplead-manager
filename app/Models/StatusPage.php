<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatusPage extends Model
{
    protected $fillable = [
        'user_id',
        'client_id',
        'slug',
        'title',
        'description',
        'logo_url',
        'primary_color',
        'custom_domain',
        'is_public',
        'show_uptime_percentage',
        'show_response_time',
        'show_incident_history',
        'incident_history_days',
        'auto_incidents',
        'password_hash',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'show_uptime_percentage' => 'boolean',
        'show_response_time' => 'boolean',
        'show_incident_history' => 'boolean',
        'incident_history_days' => 'integer',
        'auto_incidents' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function statusPageSites(): HasMany
    {
        return $this->hasMany(StatusPageSite::class)->orderBy('sort_order');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(StatusPageIncident::class);
    }

    public function activeIncidents(): HasMany
    {
        return $this->hasMany(StatusPageIncident::class)->where('status', '!=', 'resolved');
    }

    protected function overallStatus(): Attribute
    {
        return Attribute::get(function () {
            $sites = $this->statusPageSites()->with('site.uptimeMonitor')->get();

            if ($sites->isEmpty()) {
                return 'operational';
            }

            $hasOutage = $sites->contains(fn ($sps) => $sps->site && !$sps->site->is_up);
            if ($hasOutage) {
                return 'outage';
            }

            $hasDegraded = $sites->contains(fn ($sps) => $sps->site?->uptimeMonitor?->current_state === 'degraded');
            if ($hasDegraded) {
                return 'degraded';
            }

            return 'operational';
        });
    }

    protected function publicUrl(): Attribute
    {
        return Attribute::get(fn () => url("/status/{$this->slug}"));
    }
}
