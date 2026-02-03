<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SslCheckHistory extends Model
{
    public $timestamps = false;

    protected $table = 'ssl_check_history';

    protected $fillable = [
        'ssl_certificate_id',
        'status',
        'days_remaining',
        'issuer',
        'protocol',
        'cipher',
        'chain_valid',
        'handshake_time',
        'error_message',
        'checked_at',
    ];

    protected $casts = [
        'chain_valid' => 'boolean',
        'checked_at' => 'datetime',
        'days_remaining' => 'integer',
        'handshake_time' => 'integer',
    ];

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(SslCertificate::class, 'ssl_certificate_id');
    }
}
