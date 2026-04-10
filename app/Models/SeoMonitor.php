<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property bool $is_active
 * @property int $interval_minutes
 * @property \Illuminate\Support\Carbon|null $next_audit_at
 * @property \Illuminate\Support\Carbon|null $last_audit_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class SeoMonitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'is_active',
        'interval_minutes',
        'next_audit_at',
        'last_audit_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'interval_minutes' => 'integer',
        'next_audit_at' => 'datetime',
        'last_audit_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
