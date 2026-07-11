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

    /**
     * Unban IPs on the WordPress side (connector >= 2.17: clears the ban
     * option and the brute-force transient). Returns the full response so
     * callers can inspect results.unban and the fresh banned_ips list.
     */
    public function unbanIps(array $ips): array
    {
        $response = $this->request('POST', '/security-settings', ['unban_ips' => $ips]);
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
