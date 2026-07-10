<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int $consecutive_failures
 * @property \Illuminate\Support\Carbon|null $last_failure_at
 * @property string|null $last_failure_reason
 * @property string $circuit_state
 * @property \Illuminate\Support\Carbon|null $circuit_opened_at
 * @property int $circuit_breaks_last_24h
 * @property \Illuminate\Support\Carbon|null $circuit_breaks_reset_at
 * @property bool $is_monitoring_disabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
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
        'domain_breakers',
    ];

    protected $casts = [
        'consecutive_failures' => 'integer',
        'last_failure_at' => 'datetime',
        'circuit_opened_at' => 'datetime',
        'circuit_breaks_last_24h' => 'integer',
        'circuit_breaks_reset_at' => 'datetime',
        'is_monitoring_disabled' => 'boolean',
        'domain_breakers' => 'array',
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
