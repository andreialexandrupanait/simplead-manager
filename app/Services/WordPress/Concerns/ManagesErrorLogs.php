<?php

declare(strict_types=1);

namespace App\Services\WordPress\Concerns;

trait ManagesErrorLogs
{
    public function getErrorLogs(int $limit = 100): array
    {
        $response = $this->request('GET', '/error-logs', ['limit' => $limit]);
        $response->throw();

        return $response->json();
    }
}
