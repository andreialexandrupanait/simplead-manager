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
}
