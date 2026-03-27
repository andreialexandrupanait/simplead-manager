<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property bool $is_enabled
 * @property string $frequency
 * @property array|null $auto_clean_types
 * @property \Illuminate\Support\Carbon|null $next_cleanup_at
 * @property \Illuminate\Support\Carbon|null $last_cleanup_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class DatabaseCleanupConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'is_enabled',
        'frequency',
        'auto_clean_types',
        'next_cleanup_at',
        'last_cleanup_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'auto_clean_types' => 'array',
        'next_cleanup_at' => 'datetime',
        'last_cleanup_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
