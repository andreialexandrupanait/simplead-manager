<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatusPageIncident extends Model
{
    protected $fillable = [
        'status_page_id',
        'site_id',
        'title',
        'description',
        'status',
        'severity',
        'is_scheduled',
        'scheduled_start_at',
        'scheduled_end_at',
        'started_at',
        'resolved_at',
        'auto_created',
    ];

    protected $casts = [
        'is_scheduled' => 'boolean',
        'auto_created' => 'boolean',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(StatusPageIncidentUpdate::class)->orderByDesc('created_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'resolved');
    }

    public function scopeRecent(Builder $query, int $days = 90): Builder
    {
        return $query->where('started_at', '>=', now()->subDays($days));
    }

    protected function severityColor(): Attribute
    {
        return Attribute::get(fn () => match ($this->severity) {
            'critical' => 'red',
            'major' => 'orange',
            default => 'yellow',
        });
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn () => match ($this->status) {
            'investigating' => 'Investigating',
            'identified' => 'Identified',
            'monitoring' => 'Monitoring',
            'resolved' => 'Resolved',
            default => ucfirst($this->status),
        });
    }

    protected function duration(): Attribute
    {
        return Attribute::get(function () {
            if (!$this->started_at) {
                return null;
            }

            $end = $this->resolved_at ?? now();

            return $this->started_at->diffForHumans($end, true);
        });
    }
}
