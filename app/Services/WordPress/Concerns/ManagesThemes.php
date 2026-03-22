<?php

namespace App\Services\WordPress\Concerns;

trait ManagesThemes
{
    public function getThemes(): array
    {
        $response = $this->request('GET', '/themes');
        $response->throw();
        return $response->json();
    }

    public function updateThemes(array $themeSlugs): array
    {
        $response = $this->request('POST', '/themes/update', [
            'themes' => $themeSlugs,
        ]);
        $this->throwIfFailed($response);
        return $response->json();
    }

    public function activateTheme(string $themeSlug): array
    {
        $response = $this->request('POST', '/themes/activate', [
            'theme' => $themeSlug,
        ]);
        $this->throwIfFailed($response);
        return $response->json();
    }

    public function deleteTheme(string $themeSlug): array
    {
        $response = $this->request('POST', '/themes/delete', [
            'theme' => $themeSlug,
        ]);
        $this->throwIfFailed($response);
        return $response->json();
    }
}
