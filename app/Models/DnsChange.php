<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsChange extends Model
{
    protected $fillable = [
        'dns_monitor_id', 'record_type', 'old_value', 'new_value',
        'detected_at', 'acknowledged_at',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'detected_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(DnsMonitor::class, 'dns_monitor_id');
    }
}
