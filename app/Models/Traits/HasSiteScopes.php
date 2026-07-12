<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Enums\HealthLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait HasSiteScopes
{
    /**
     * Canonical tenant-visibility scope — the single source of truth for
     * "which sites may this user see". Mirrors User::canAccessSite(): admins
     * see every site; everyone else sees the sites they own directly OR reach
     * through an assigned client. Use this (directly, or via a whereHas('site')
     * on a related model) for every list, search and aggregate so the read path
     * cannot drift from the authorization path.
     *
     * A null user (e.g. an out-of-request/queue context that passes no user)
     * is denied everything rather than shown the whole fleet.
     */
    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin()) {
            return $query;
        }

        $table = $query->getModel()->getTable();
        $clientIds = $user->assignedClients()->pluck('clients.id')->all();

        return $query->where(function (Builder $inner) use ($user, $table, $clientIds) {
            $inner->where("{$table}.user_id", $user->id);

            if ($clientIds !== []) {
                $inner->orWhereIn("{$table}.client_id", $clientIds);
            }
        });
    }

    public function scopePortfolio(Builder $query): Builder
    {
        return $query->where('is_prospect', false);
    }

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
