<?php

namespace App\Models\Traits;

use App\Enums\HealthLevel;
use Illuminate\Database\Eloquent\Builder;

trait HasSiteScopes
{
    public function scopeHealthy(Builder $query): Builder
    {
        return $query->where('health_score', '>=', HealthLevel::HEALTHY_THRESHOLD)->where('is_up', true);
    }

    public function scopeWarning(Builder $query): Builder
    {
        return $query->whereBetween('health_score', [HealthLevel::WARNING_THRESHOLD, HealthLevel::HEALTHY_THRESHOLD - 1])->where('is_up', true);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('health_score', '<', HealthLevel::WARNING_THRESHOLD)->orWhere('is_up', false);
        });
    }

    public function scopeSearchable(Builder $query, string $term): Builder
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'ilike', "%{$term}%")
              ->orWhere('url', 'ilike', "%{$term}%");
        });
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('is_connected', true);
    }

    public function scopeWithPendingUpdates(Builder $query): Builder
    {
        return $query->where('pending_updates_count', '>', 0);
    }
}
