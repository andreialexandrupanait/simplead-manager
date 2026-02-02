<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SslCertificate extends Model
{
    protected $fillable = [
        'site_id',
        'domain',
        'issuer',
        'issuer_organisation',
        'san_domains',
        'signature_algorithm',
        'key_size',
        'protocol',
        'cipher',
        'issued_at',
        'expires_at',
        'days_remaining',
        'chain_valid',
        'status',
        'error_message',
        'handshake_time',
        'alerts_enabled',
        'warn_days',
        'last_alert_sent_at',
        'last_checked_at',
        'next_check_at',
    ];

    protected $casts = [
        'san_domains' => 'array',
        'chain_valid' => 'boolean',
        'alerts_enabled' => 'boolean',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_alert_sent_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'days_remaining' => 'integer',
        'key_size' => 'integer',
        'handshake_time' => 'integer',
        'warn_days' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(SslCheckHistory::class);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'valid' => 'green',
            'expiring_soon' => 'yellow',
            'expired', 'error' => 'red',
            default => 'gray',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'valid' => 'Valid',
            'expiring_soon' => 'Expiring Soon',
            'expired' => 'Expired',
            'error' => 'Error',
            'pending' => 'Pending',
            default => 'Unknown',
        };
    }
}
