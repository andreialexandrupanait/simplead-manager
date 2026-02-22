<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DomainMonitor extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'site_id',
        'domain',
        'tld',
        'registrar',
        'registrar_url',
        'registered_at',
        'expires_at',
        'updated_at',
        'days_remaining',
        'nameservers',
        'dns_provider',
        'domain_statuses',
        'status',
        'error_message',
        'alerts_enabled',
        'warn_days',
        'last_alert_sent_at',
        'last_checked_at',
        'next_check_at',
    ];

    protected $casts = [
        'nameservers' => 'array',
        'domain_statuses' => 'array',
        'alerts_enabled' => 'boolean',
        'registered_at' => 'datetime',
        'expires_at' => 'datetime',
        'updated_at' => 'datetime',
        'last_alert_sent_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'days_remaining' => 'integer',
        'warn_days' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(DomainCheckHistory::class);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'active' => 'green',
            'expiring_soon' => 'yellow',
            'expired', 'error' => 'red',
            default => 'gray',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'active' => 'Active',
            'expiring_soon' => 'Expiring Soon',
            'expired' => 'Expired',
            'error' => 'Error',
            'pending' => 'Pending',
            default => 'Unknown',
        };
    }
}
