<?php

namespace App\Console\Commands;

use App\Jobs\FetchSiteFavicon;
use App\Models\Site;
use Illuminate\Console\Command;

class FaviconBackfillCommand extends Command
{
    protected $signature = 'sites:backfill-favicons';
    protected $description = 'Dispatch favicon fetch jobs for sites missing favicons';

    public function handle(): void
    {
        Site::whereNull('favicon_path')
            ->each(fn ($site) => FetchSiteFavicon::dispatch($site));
    }
}
