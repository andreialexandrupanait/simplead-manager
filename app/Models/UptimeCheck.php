<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UptimeCheck extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'monitor_id',
        'is_up',
        'response_time',
        'status_code',
        'failure_reason',
        'keyword_found',
        'ssl_expires_at',
        'checked_at',
    ];

    protected $casts = [
        'is_up' => 'boolean',
        'keyword_found' => 'boolean',
        'checked_at' => 'datetime',
        'ssl_expires_at' => 'datetime',
        'response_time' => 'integer',
        'status_code' => 'integer',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(UptimeMonitor::class, 'monitor_id');
    }
}
