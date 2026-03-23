<?php

declare(strict_types=1);

namespace App\Services\WordPress\Concerns;

trait ManagesDatabase
{
    public function getDbCleanupStats(): array
    {
        $response = $this->request('GET', '/db-cleanup-stats');
        $response->throw();

        return $response->json();
    }

    public function runDbCleanup(array $options): array
    {
        $response = $this->request('POST', '/db-cleanup-run', $options);
        $response->throw();

        return $response->json();
    }

    public function getDatabaseHealth(): array
    {
        $response = $this->request('GET', '/database-health');
        $response->throw();

        return $response->json();
    }
}
