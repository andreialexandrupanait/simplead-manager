<?php

declare(strict_types=1);

namespace App\Services\WordPress\Concerns;

trait ManagesSeo
{
    public function getSeoAnalysis(): array
    {
        $response = $this->request('GET', '/seo/analysis', [], [], 60);
        $response->throw();

        return $response->json();
    }

    public function updateSeoMeta(string $url, ?string $metaTitle = null, ?string $metaDescription = null): array
    {
        $response = $this->request('POST', '/seo/update-meta', array_filter([
            'url' => $url,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
        ]), [], 30);
        $response->throw();

        return $response->json();
    }
}
