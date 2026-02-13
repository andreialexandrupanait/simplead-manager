<?php

namespace App\Livewire\Traits;

use App\Models\Site;
use Illuminate\Auth\Access\AuthorizationException;

trait WithSiteAuthorization
{
    protected function authorizeSiteAccess(Site $site): void
    {
        $user = auth()->user();

        if (!$user) {
            throw new AuthorizationException('Unauthenticated.');
        }

        if ($user->is_admin) {
            return;
        }

        if ($site->user_id !== $user->id) {
            abort(403, 'You do not have access to this site.');
        }
    }

    protected function authorizeSiteModification(Site $site): void
    {
        $this->authorizeSiteAccess($site);
    }
}
