<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteReportConfig extends Model
{
    protected $fillable = [
        'site_id',
        'language',
        'show_security',
        'show_cloudflare',
        'custom_notes',
    ];

    protected $casts = [
        'show_security' => 'boolean',
        'show_cloudflare' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
