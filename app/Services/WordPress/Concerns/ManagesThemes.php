<?php

declare(strict_types=1);

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
        // Updates run an unbounded task on the WP host — do not abandon the call
        // at the 30s default and lose the result (P1-41).
        $response = $this->request('POST', '/themes/update', [
            'themes' => $themeSlugs,
        ], timeout: self::UPDATE_REQUEST_TIMEOUT);
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
