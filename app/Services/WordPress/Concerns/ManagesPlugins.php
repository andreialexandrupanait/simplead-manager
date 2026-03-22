<?php

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
        $response = $this->request('POST', '/plugins/update', [
            'plugins' => $pluginFiles,
        ]);
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
