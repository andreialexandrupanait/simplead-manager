<?php

declare(strict_types=1);

namespace App\Services\WordPress\Concerns;

trait ManagesRedirects
{
    /**
     * Replace the site's full redirect set on the connector.
     *
     * @param  list<array{source: string, target: string, code: int}>  $redirects
     */
    public function setRedirects(array $redirects): array
    {
        $response = $this->request('POST', '/redirects', ['redirects' => $redirects]);
        $this->throwIfFailed($response);

        return $response->json();
    }

    public function getRedirects(): array
    {
        $response = $this->request('GET', '/redirects');
        $this->throwIfFailed($response);

        return $response->json();
    }
}
