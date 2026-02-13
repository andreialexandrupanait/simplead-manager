<?php

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

class BackupPolicy
{
    public function view(User $user, Backup $backup): bool
    {
        return $user->is_admin || $backup->site?->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Backup $backup): bool
    {
        return $user->is_admin || $backup->site?->user_id === $user->id;
    }

    public function restore(User $user, Backup $backup): bool
    {
        return $user->is_admin || $backup->site?->user_id === $user->id;
    }
}
