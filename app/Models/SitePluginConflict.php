<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property string $plugin_a_slug
 * @property string $plugin_b_slug
 * @property int|null $plugin_conflict_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $detected_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \App\Models\PluginConflict|null $conflict
 */
class SitePluginConflict extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'plugin_a_slug',
        'plugin_b_slug',
        'plugin_conflict_id',
        'status',
        'detected_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function conflict(): BelongsTo
    {
        return $this->belongsTo(PluginConflict::class, 'plugin_conflict_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
