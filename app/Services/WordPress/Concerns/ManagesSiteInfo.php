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

    public function updateCore(): array
    {
        $response = $this->request('POST', '/core/update');
        $response->throw();

        return $response->json();
    }

    public function rollback(string $type, string $slug, string $version): array
    {
        $response = $this->request('POST', "/rollback/{$type}", [
            'slug' => $slug,
            'version' => $version,
        ]);
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
}
