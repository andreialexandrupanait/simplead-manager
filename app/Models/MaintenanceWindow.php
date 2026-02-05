<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'user_id',
        'title',
        'description',
        'scheduled_start_at',
        'scheduled_end_at',
        'actual_start_at',
        'actual_end_at',
        'status',
        'pause_uptime',
        'pause_ssl',
        'pause_performance',
        'pause_backups',
        'pause_links',
        'notify_on_start',
        'notify_on_end',
        'update_status_page',
    ];

    protected $casts = [
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'actual_start_at' => 'datetime',
        'actual_end_at' => 'datetime',
        'pause_uptime' => 'boolean',
        'pause_ssl' => 'boolean',
        'pause_performance' => 'boolean',
        'pause_backups' => 'boolean',
        'pause_links' => 'boolean',
        'notify_on_start' => 'boolean',
        'notify_on_end' => 'boolean',
        'update_status_page' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPausing(string $monitorType): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return match ($monitorType) {
            'uptime' => $this->pause_uptime,
            'ssl' => $this->pause_ssl,
            'performance' => $this->pause_performance,
            'backups' => $this->pause_backups,
            'links' => $this->pause_links,
            default => false,
        };
    }
}
