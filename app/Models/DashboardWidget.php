<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $widget_type
 * @property array|null $config
 * @property int $grid_x
 * @property int $grid_y
 * @property int $grid_w
 * @property int $grid_h
 * @property bool $is_visible
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 */
class DashboardWidget extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'widget_type',
        'config',
        'grid_x',
        'grid_y',
        'grid_w',
        'grid_h',
        'is_visible',
        'sort_order',
    ];

    protected $casts = [
        'config' => 'array',
        'is_visible' => 'boolean',
        'grid_x' => 'integer',
        'grid_y' => 'integer',
        'grid_w' => 'integer',
        'grid_h' => 'integer',
        'sort_order' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisibleForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)
            ->where('is_visible', true)
            ->orderBy('sort_order');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)
            ->orderBy('sort_order');
    }
}
