<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StatusPage;
use App\Models\User;

class StatusPagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StatusPage $statusPage): bool
    {
        return $user->isAdmin() || $statusPage->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->canManageSites();
    }

    public function update(User $user, StatusPage $statusPage): bool
    {
        if ($user->isViewer()) {
            return false;
        }

        return $user->isAdmin() || $statusPage->user_id === $user->id;
    }

    public function delete(User $user, StatusPage $statusPage): bool
    {
        return $user->isAdmin();
    }
}
