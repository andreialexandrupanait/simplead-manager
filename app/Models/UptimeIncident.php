<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UptimeIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'monitor_id',
        'status',
        'cause',
        'started_at',
        'resolved_at',
        'notified_via',
        'notified_at',
    ];

    protected $casts = [
        'notified_via' => 'array',
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(UptimeMonitor::class, 'monitor_id');
    }

    public function getDurationAttribute(): string
    {
        $end = $this->resolved_at ?? now();
        $minutes = (int) $this->started_at->diffInMinutes($end);

        if ($minutes < 60) {
            return $minutes . 'm';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours < 24) {
            return $remainingMinutes > 0
                ? "{$hours}h {$remainingMinutes}m"
                : "{$hours}h";
        }

        $days = intdiv($hours, 24);
        $remainingHours = $hours % 24;

        return $remainingHours > 0
            ? "{$days}d {$remainingHours}h"
            : "{$days}d";
    }
}
