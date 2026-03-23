<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\Site;
use Illuminate\Auth\Access\AuthorizationException;

trait WithSiteAuthorization
{
    protected function authorizeSiteAccess(Site $site): void
    {
        $user = auth()->user();

        if (! $user) {
            throw new AuthorizationException('Unauthenticated.');
        }

        if ($user->isAdmin()) {
            return;
        }

        if ($site->user_id !== $user->id) {
            abort(403, 'You do not have access to this site.');
        }
    }

    protected function authorizeSiteModification(Site $site): void
    {
        $user = auth()->user();

        if (! $user) {
            throw new AuthorizationException('Unauthenticated.');
        }

        if ($user->isViewer()) {
            abort(403, 'Viewers cannot modify sites.');
        }

        $this->authorizeSiteAccess($site);
    }
}
