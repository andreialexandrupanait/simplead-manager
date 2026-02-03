<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Link extends Model
{
    protected $fillable = [
        'site_id',
        'link_scan_id',
        'url',
        'url_hash',
        'type',
        'link_type',
        'source_url',
        'source_title',
        'anchor_text',
        'element',
        'status',
        'http_code',
        'final_url',
        'redirect_count',
        'response_time_ms',
        'error_message',
        'is_permanent_redirect',
        'is_dismissed',
        'dismissed_reason',
        'first_detected_at',
        'last_checked_at',
    ];

    protected $casts = [
        'is_permanent_redirect' => 'boolean',
        'is_dismissed' => 'boolean',
        'first_detected_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(LinkScan::class, 'link_scan_id');
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'ok' => 'green',
            'broken' => 'red',
            'redirect' => 'yellow',
            'timeout' => 'orange',
            'ssl_error' => 'red',
            'dns_error' => 'red',
            default => 'gray',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'ok' => 'OK',
            'broken' => $this->http_code ? "Broken ({$this->http_code})" : 'Broken',
            'redirect' => $this->http_code ? "Redirect ({$this->http_code})" : 'Redirect',
            'timeout' => 'Timeout',
            'ssl_error' => 'SSL Error',
            'dns_error' => 'DNS Error',
            'pending' => 'Pending',
            default => ucfirst($this->status),
        };
    }
}
