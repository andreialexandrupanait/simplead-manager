<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Client $client): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $client->sites()->where('user_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Client $client): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $client->sites()->where('user_id', $user->id)->exists();
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->is_admin;
    }
}
