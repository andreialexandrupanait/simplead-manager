<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainCheckHistory extends Model
{
    public $timestamps = false;

    protected $table = 'domain_check_history';

    protected $fillable = [
        'domain_monitor_id',
        'status',
        'days_remaining',
        'registrar',
        'nameservers',
        'error_message',
        'checked_at',
    ];

    protected $casts = [
        'nameservers' => 'array',
        'checked_at' => 'datetime',
        'days_remaining' => 'integer',
    ];

    public function domainMonitor(): BelongsTo
    {
        return $this->belongsTo(DomainMonitor::class);
    }
}
