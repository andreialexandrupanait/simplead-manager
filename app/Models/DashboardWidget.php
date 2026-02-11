<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class DashboardWidget extends Model
{
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
