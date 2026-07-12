<?php

declare(strict_types=1);

namespace App\Services\WordPress\Concerns;

trait ManagesPlugins
{
    public function getPlugins(): array
    {
        $response = $this->request('GET', '/plugins');
        $response->throw();

        return $response->json();
    }

    public function updatePlugins(array $pluginFiles): array
    {
        // Updates run an unbounded task on the WP host — do not abandon the call
        // at the 30s default and lose the result (P1-41).
        $response = $this->request('POST', '/plugins/update', [
            'plugins' => $pluginFiles,
        ], timeout: self::UPDATE_REQUEST_TIMEOUT);
        $this->throwIfFailed($response);

        return $response->json();
    }

    public function activatePlugin(string $pluginFile): array
    {
        $response = $this->request('POST', '/plugins/activate', [
            'plugin' => $pluginFile,
        ]);
        $this->throwIfFailed($response);

        return $response->json();
    }

    public function deactivatePlugin(string $pluginFile): array
    {
        $response = $this->request('POST', '/plugins/deactivate', [
            'plugin' => $pluginFile,
        ]);
        $this->throwIfFailed($response);

        return $response->json();
    }

    public function deletePlugin(string $pluginFile): array
    {
        $response = $this->request('POST', '/plugins/delete', [
            'plugin' => $pluginFile,
        ]);
        $this->throwIfFailed($response);

        return $response->json();
    }
}
