<?php

declare(strict_types=1);

namespace App\Services\WordPress\Concerns;

trait ManagesErrorLogs
{
    public function getErrorLogs(int $limit = 100): array
    {
        // GET args must ride the query string, not the signed body — see
        // WordPressHttpClient::request() (GET-with-body HMAC mismatch).
        $response = $this->request('GET', '/error-logs', [], ['limit' => $limit]);
        $response->throw();

        return $response->json();
    }
}
