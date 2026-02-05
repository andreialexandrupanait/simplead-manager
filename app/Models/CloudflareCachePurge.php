<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CloudflareCachePurge extends Model
{
    protected $fillable = [
        'site_cloudflare_id',
        'type',
        'targets',
        'purged_by',
        'purged_at',
    ];

    protected $casts = [
        'targets' => 'array',
        'purged_at' => 'datetime',
    ];

    public function siteCloudflare(): BelongsTo
    {
        return $this->belongsTo(SiteCloudflare::class);
    }

    public function purgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purged_by');
    }
}
