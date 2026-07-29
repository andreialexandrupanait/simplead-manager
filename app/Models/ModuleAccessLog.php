<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faza 7 — usage-analytics access log for the modules under observation.
 * One row per (debounced) access; aggregated at the 60-day decision point.
 *
 * @property int $id
 * @property string $module
 * @property int|null $site_id
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon $accessed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read \App\Models\Site|null $site
 * @property-read \App\Models\User|null $user
 */
class ModuleAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'module',
        'site_id',
        'user_id',
        'accessed_at',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'user_id' => 'integer',
        'accessed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
