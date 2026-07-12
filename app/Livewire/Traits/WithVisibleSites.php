<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\Site;

/**
 * Helper for the read-leak sweep (P1-02): overview/search surfaces that list a
 * site-related model (backups, monitors, reports, plugins, activity …) must be
 * restricted to the acting user's visible sites. The canonical definition lives
 * in Site::visibleTo(); this pre-resolves it to an id set so it can be applied
 * as a plain whereIn on the related model's `site_id` (Eloquent scopes can't be
 * called on the generic builder inside a whereHas closure).
 */
trait WithVisibleSites
{
    /**
     * Ids of the sites the acting user may see, or null for admins / no-user
     * contexts (meaning "no restriction").
     *
     * @return array<int>|null
     */
    protected function visibleSiteIds(): ?array
    {
        $user = auth()->user();

        if (! $user || $user->isAdmin()) {
            return null;
        }

        return Site::query()->visibleTo($user)->pluck('id')->all();
    }
}
