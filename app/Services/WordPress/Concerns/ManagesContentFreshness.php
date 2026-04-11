<?php

declare(strict_types=1);

namespace App\Services\WordPress\Concerns;

trait ManagesContentFreshness
{
    public function getContentFreshness(int $perPage = 200): array
    {
        $response = $this->request('GET', '/content-freshness', ['per_page' => $perPage]);
        $response->throw();

        return $response->json();
    }
}
