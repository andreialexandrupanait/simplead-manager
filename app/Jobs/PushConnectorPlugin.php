<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushConnectorPlugin implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public Site $site,
        public string $downloadUrl,
        public string $pushId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $result = ['site' => $this->site->name, 'site_id' => $this->site->id];

        try {
            $api = app(WordPressApiServiceFactory::class)->make($this->site);
            $response = $api->request('POST', '/self-update', [
                'download_url' => $this->downloadUrl,
            ], [], 120);

            if ($response->successful()) {
                $data = $response->json();

                if (! empty($data['new_version']) && $data['new_version'] !== 'unknown') {
                    $this->site->update(['connector_version' => $data['new_version']]);
                }

                // Flush OPcache via standalone file
                $flushUrl = rtrim($this->site->url, '/').'/wp-content/plugins/simplead-manager-connector/opcache-flush.php';
                try {
                    Http::timeout(10)->get($flushUrl);
                } catch (\Throwable) {
                }

                // Also try REST API endpoint as safety net
                try {
                    $api->request('POST', '/flush-opcache', [], [], 15);
                } catch (\Throwable) {
                }

                $result['status'] = 'success';
                $result['message'] = ($data['old_version'] ?? '?').' -> '.($data['new_version'] ?? '?');
            } else {
                $error = $response->json('error.message') ?? "HTTP {$response->status()}";
                $result['status'] = 'error';
                $result['message'] = $error;
            }
        } catch (\Throwable $e) {
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();

            Log::warning('PushConnectorPlugin failed', [
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Store result and increment completed counter
        $cacheKey = "connector-push:{$this->pushId}";
        $results = Cache::get("{$cacheKey}:results", []);
        $results[] = $result;
        Cache::put("{$cacheKey}:results", $results, 3600);
        Cache::increment("{$cacheKey}:completed");
    }
}
