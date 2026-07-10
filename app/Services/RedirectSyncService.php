<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Models\SiteRedirect;

/**
 * Pushes a site's active redirect rules to the connector, which performs them
 * on the front end. The connector replaces its full set on each push, so this
 * always sends the complete active list.
 */
class RedirectSyncService
{
    public function __construct(private readonly WordPressApiServiceFactory $apiFactory) {}

    public function push(Site $site): void
    {
        $rules = $site->redirects()
            ->where('is_active', true)
            ->get()
            ->map(fn (SiteRedirect $r) => [
                'source' => $r->source_path,
                'target' => $r->target_url,
                'code' => $r->status_code,
            ])
            ->values()
            ->all();

        $this->apiFactory->make($site)->setRedirects($rules);
    }
}
