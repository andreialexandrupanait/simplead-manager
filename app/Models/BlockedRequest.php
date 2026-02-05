<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedRequest extends Model
{
    protected $fillable = [
        'site_id',
        'ip_rule_id',
        'ip_address',
        'request_url',
        'user_agent',
        'blocked_at',
    ];

    protected $casts = [
        'blocked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function ipRule(): BelongsTo
    {
        return $this->belongsTo(IpRule::class);
    }
}
