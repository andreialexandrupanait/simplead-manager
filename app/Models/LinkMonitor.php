<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LinkMonitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'is_active',
        'frequency',
        'scan_time',
        'day_of_week',
        'max_pages',
        'max_depth',
        'check_external',
        'check_images',
        'timeout_seconds',
        'exclude_paths',
        'exclude_domains',
        'alert_on_broken',
        'alert_threshold',
        'total_links',
        'broken_links',
        'redirects',
        'pages_scanned',
        'last_scan_at',
        'next_scan_at',
        'last_scan_status',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'check_external' => 'boolean',
        'check_images' => 'boolean',
        'alert_on_broken' => 'boolean',
        'exclude_paths' => 'array',
        'exclude_domains' => 'array',
        'last_scan_at' => 'datetime',
        'next_scan_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(LinkScan::class);
    }

    public function latestScan(): HasOne
    {
        return $this->hasOne(LinkScan::class)->latestOfMany();
    }

    public function latestCompletedScan(): HasOne
    {
        return $this->hasOne(LinkScan::class)
            ->where('status', 'completed')
            ->latestOfMany();
    }
}
