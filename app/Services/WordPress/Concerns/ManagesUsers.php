<?php

declare(strict_types=1);

namespace App\Services\WordPress\Concerns;

trait ManagesUsers
{
    public function getUsers(): array
    {
        $response = $this->request('GET', '/users', [], [], 90);
        $response->throw();

        return $response->json();
    }

    public function createUser(array $data): array
    {
        $response = $this->request('POST', '/users/create', $data);
        $this->throwIfFailed($response);

        return $response->json();
    }

    public function updateUser(int $wpUserId, array $data): array
    {
        $response = $this->request('POST', '/users/update', array_merge($data, [
            'wp_user_id' => $wpUserId,
        ]));
        $this->throwIfFailed($response);

        return $response->json();
    }

    public function deleteUser(int $wpUserId, ?int $reassignTo = null): array
    {
        $data = ['wp_user_id' => $wpUserId];
        if ($reassignTo !== null) {
            $data['reassign_to'] = $reassignTo;
        }
        $response = $this->request('POST', '/users/delete', $data);
        $this->throwIfFailed($response);

        return $response->json();
    }

    public function bulkDeleteUsers(array $wpUserIds, ?int $reassignTo = null): array
    {
        $data = ['user_ids' => $wpUserIds];
        if ($reassignTo !== null) {
            $data['reassign_to'] = $reassignTo;
        }
        $response = $this->request('POST', '/users/bulk-delete', $data);
        $this->throwIfFailed($response);

        return $response->json();
    }
}
