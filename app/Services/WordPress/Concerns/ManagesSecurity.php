<?php

declare(strict_types=1);

namespace App\Services\WordPress\Concerns;

trait ManagesSecurity
{
    public function getSecurityCheck(): array
    {
        $response = $this->request('GET', '/security-check');
        $response->throw();

        return $response->json();
    }

    public function pushSecuritySettings(array $settings): array
    {
        $response = $this->request('POST', '/security-settings', $settings);
        $this->throwIfFailed($response);

        return $response->json();
    }

    public function getSecurityState(): array
    {
        $response = $this->request('GET', '/security-state');
        $response->throw();

        return $response->json();
    }

    public function applySecurityFix(string $key): array
    {
        $response = $this->request('POST', '/security-fix', [
            'key' => $key,
        ]);
        $response->throw();

        return $response->json();
    }
}
