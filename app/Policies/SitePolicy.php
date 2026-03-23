<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Site $site): bool
    {
        return $user->isAdmin() || $site->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->canManageSites();
    }

    public function update(User $user, Site $site): bool
    {
        if ($user->isViewer()) {
            return false;
        }

        return $user->isAdmin() || $site->user_id === $user->id;
    }

    public function delete(User $user, Site $site): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        return true;
    }
}
