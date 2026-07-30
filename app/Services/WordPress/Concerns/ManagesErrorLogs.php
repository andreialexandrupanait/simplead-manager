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

    /**
     * SPEC §5.6 — the deduplicated aggregate from the connector's OWN error
     * handler (connector >= 2.20.0), rather than a tail of debug.log.
     *
     * @return array{success?: bool, enabled?: bool, errors?: array<int, array<string, mixed>>}
     */
    public function getPhpErrors(): array
    {
        $response = $this->request('GET', '/php-errors');
        $response->throw();

        return $response->json();
    }

    /**
     * Tell the site which signatures we have stored, so it can forget them.
     * Separate from the read on purpose: nothing is dropped until it is safe.
     *
     * @param  list<string>  $signatures  empty clears everything
     */
    public function acknowledgePhpErrors(array $signatures = []): array
    {
        $response = $this->request('POST', '/php-errors/ack', ['signatures' => $signatures]);
        $response->throw();

        return $response->json();
    }

    /**
     * SPEC §5.6 point 4 — the remote kill switch.
     */
    public function togglePhpErrorCollection(bool $enabled): array
    {
        $response = $this->request('POST', '/php-errors/toggle', ['enabled' => $enabled]);
        $response->throw();

        return $response->json();
    }
}
