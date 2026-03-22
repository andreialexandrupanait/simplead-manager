<?php

namespace App\Services\WordPress\Concerns;

trait ManagesUsers
{
    public function getUsers(): array
    {
        $response = $this->request('GET', '/users');
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
}
