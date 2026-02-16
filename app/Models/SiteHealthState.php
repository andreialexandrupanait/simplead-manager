<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteHealthState extends Model
{
    use HasFactory;
    protected $table = 'site_health_state';

    protected $fillable = [
        'site_id',
        'consecutive_failures',
        'last_failure_at',
        'last_failure_reason',
        'circuit_state',
        'circuit_opened_at',
        'circuit_breaks_last_24h',
        'circuit_breaks_reset_at',
        'is_monitoring_disabled',
    ];

    protected $casts = [
        'consecutive_failures' => 'integer',
        'last_failure_at' => 'datetime',
        'circuit_opened_at' => 'datetime',
        'circuit_breaks_last_24h' => 'integer',
        'circuit_breaks_reset_at' => 'datetime',
        'is_monitoring_disabled' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isClosed(): bool
    {
        return $this->circuit_state === 'closed';
    }

    public function isOpen(): bool
    {
        return $this->circuit_state === 'open';
    }

    public function isHalfOpen(): bool
    {
        return $this->circuit_state === 'half_open';
    }
}
