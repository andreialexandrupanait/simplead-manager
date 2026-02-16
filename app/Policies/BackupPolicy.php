<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

class BackupPolicy
{
    public function view(User $user, Backup $backup): bool
    {
        return $user->isAdmin() || $backup->site?->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->canManageSites();
    }

    public function delete(User $user, Backup $backup): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Backup $backup): bool
    {
        if ($user->isViewer()) {
            return false;
        }

        return $user->isAdmin() || $backup->site?->user_id === $user->id;
    }
}
