<?php

declare(strict_types=1);

namespace App\Services\WordPress\Concerns;

trait ManagesSiteInfo
{
    public function getInfo(): array
    {
        $response = $this->request('GET', '/info');
        $response->throw();

        return $response->json();
    }

    public function getLoginUrl(?string $user = null): array
    {
        $data = [];
        if ($user) {
            $data['user'] = $user;
        }

        $response = $this->request('POST', '/login-url', $data);
        $response->throw();

        return $response->json();
    }

    public function getCoreIntegrityCheck(): array
    {
        $response = $this->request('GET', '/core-integrity-check');
        $response->throw();

        return $response->json();
    }

    public function getThemeIntegrityCheck(string $slug): array
    {
        // GET args must ride the query string, not the signed body — see
        // WordPressHttpClient::request() (GET-with-body HMAC mismatch).
        $response = $this->request('GET', '/theme-integrity-check', [], ['slug' => $slug]);
        $response->throw();

        return $response->json();
    }

    public function updateCore(): array
    {
        // Core updates run an unbounded task on the WP host — do not abandon the
        // call at the 30s default and lose the result (P1-41).
        $response = $this->request('POST', '/core/update', timeout: self::UPDATE_REQUEST_TIMEOUT);
        $response->throw();

        return $response->json();
    }

    public function rollback(string $type, string $slug, string $version): array
    {
        // A rollback reinstalls a prior version — the same unbounded work as an
        // update, and it is the safety net, so give it the update timeout (P1-41).
        $response = $this->request('POST', "/rollback/{$type}", [
            'slug' => $slug,
            'version' => $version,
        ], timeout: self::UPDATE_REQUEST_TIMEOUT);
        $response->throw();

        return $response->json();
    }

    public function healthCheck(): array
    {
        $response = $this->request('GET', '/health');
        $response->throw();

        return $response->json();
    }

    public function runDiagnostic(): array
    {
        $response = $this->request('GET', '/diagnostic');
        $response->throw();

        return $response->json();
    }

    public function fixElementor(): array
    {
        $response = $this->request('POST', '/diagnostic/fix-elementor');
        $response->throw();

        return $response->json();
    }

    public function clearCache(): array
    {
        $response = $this->request('POST', '/cache-clear');
        $response->throw();

        return $response->json();
    }

    public function getServerResources(): array
    {
        $response = $this->request('GET', '/server-resources');
        $response->throw();

        return $response->json();
    }

    public function rotateApiKeys(): array
    {
        $response = $this->request('POST', '/rotate-keys');
        $response->throw();

        return $response->json();
    }
}
