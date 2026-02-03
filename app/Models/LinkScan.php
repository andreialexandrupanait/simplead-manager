<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LinkScan extends Model
{
    protected $fillable = [
        'site_id',
        'link_monitor_id',
        'status',
        'trigger',
        'total_links',
        'broken_links',
        'redirects',
        'timeouts',
        'pages_scanned',
        'progress_percent',
        'progress_message',
        'error_message',
        'started_at',
        'completed_at',
        'duration_seconds',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(LinkMonitor::class, 'link_monitor_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    public function brokenLinks(): HasMany
    {
        return $this->hasMany(Link::class)->where('status', 'broken');
    }

    public function redirectLinks(): HasMany
    {
        return $this->hasMany(Link::class)->where('status', 'redirect');
    }
}
