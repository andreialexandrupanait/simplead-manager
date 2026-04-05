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

    public function optimizeTable(string $tableName): array
    {
        $response = $this->request('POST', '/db-table-optimize', [
            'table' => $tableName,
        ]);
        $response->throw();

        return $response->json();
    }

    public function convertTableEngine(string $tableName): array
    {
        $response = $this->request('POST', '/db-table-convert-engine', [
            'table' => $tableName,
        ], timeout: 120);
        $response->throw();

        return $response->json();
    }

    public function deleteTable(string $tableName): array
    {
        $response = $this->request('POST', '/db-table-delete', [
            'table' => $tableName,
        ]);
        $response->throw();

        return $response->json();
    }
}
