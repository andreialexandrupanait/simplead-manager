<?php

declare(strict_types=1);

namespace App\Services\WordPress\Concerns;

trait ManagesPosts
{
    public function createPost(array $data): array
    {
        $response = $this->request('POST', '/posts', $data, [], 60);
        $response->throw();

        return $response->json();
    }

    public function getPostCategories(): array
    {
        $response = $this->request('GET', '/posts/categories', [], [], 30);
        $response->throw();

        return $response->json()['categories'] ?? [];
    }

    public function getPostTags(): array
    {
        $response = $this->request('GET', '/posts/tags', [], [], 30);
        $response->throw();

        return $response->json()['tags'] ?? [];
    }
}
