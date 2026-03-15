<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\WordPressApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class UpdateConnectorPlugin extends Command
{
    protected $signature = 'connector:update
                            {--site= : Update a specific site by ID}
                            {--all : Update all connected sites}';

    protected $description = 'Push the latest connector plugin to WordPress sites via self-update';

    public function handle(): int
    {
        if (!$this->option('site') && !$this->option('all')) {
            $this->error('You must specify --site=ID or --all');
            return self::FAILURE;
        }

        $downloadUrl = URL::temporarySignedRoute(
            'download.connector-plugin.signed',
            now()->addMinutes(30)
        );

        if ($this->option('site')) {
            $site = Site::find($this->option('site'));
            if (!$site) {
                $this->error("Site not found: {$this->option('site')}");
                return self::FAILURE;
            }
            return $this->updateSite($site, $downloadUrl) ? self::SUCCESS : self::FAILURE;
        }

        $sites = Site::connected()->get();

        if ($sites->isEmpty()) {
            $this->warn('No connected sites found.');
            return self::SUCCESS;
        }

        $this->info("Pushing connector plugin update to {$sites->count()} site(s)...");
        $this->newLine();

        $succeeded = 0;
        $failed = 0;

        foreach ($sites as $site) {
            if ($this->updateSite($site, $downloadUrl)) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done: {$succeeded} updated, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function updateSite(Site $site, string $downloadUrl): bool
    {
        $label = "{$site->name} ({$site->url})";

        try {
            $api = new WordPressApiService($site);
            $response = $api->request('POST', '/self-update', [
                'download_url' => $downloadUrl,
            ], [], 120);

            if ($response->successful()) {
                $data = $response->json();
                $old = $data['old_version'] ?? '?';
                $new = $data['new_version'] ?? '?';
                $this->info("  ✓ {$label}: {$old} → {$new}");
                return true;
            }

            $error = $response->json('error.message') ?? $response->json('message') ?? "HTTP {$response->status()}";
            $this->error("  ✗ {$label}: {$error}");
            return false;
        } catch (\Throwable $e) {
            $this->error("  ✗ {$label}: {$e->getMessage()}");
            return false;
        }
    }
}
