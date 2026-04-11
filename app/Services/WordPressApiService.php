<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WordPressApiServiceInterface;
use App\Models\Site;
use App\Services\WordPress\Concerns\ManagesCron;
use App\Services\WordPress\Concerns\ManagesDatabase;
use App\Services\WordPress\Concerns\ManagesPlugins;
use App\Services\WordPress\Concerns\ManagesSecurity;
use App\Services\WordPress\Concerns\ManagesContentFreshness;
use App\Services\WordPress\Concerns\ManagesErrorLogs;
use App\Services\WordPress\Concerns\ManagesPosts;
use App\Services\WordPress\Concerns\ManagesSiteInfo;
use App\Services\WordPress\Concerns\ManagesThemes;
use App\Services\WordPress\Concerns\ManagesUsers;
use App\Services\WordPress\WordPressHttpClient;
use Illuminate\Http\Client\Response;

class WordPressApiService implements WordPressApiServiceInterface
{
    use ManagesContentFreshness;
    use ManagesCron;
    use ManagesErrorLogs;
    use ManagesDatabase;
    use ManagesPlugins;
    use ManagesPosts;
    use ManagesSecurity;
    use ManagesSiteInfo;
    use ManagesThemes;
    use ManagesUsers;

    public WordPressHttpClient $http;

    private ?WordPressBackupDownloader $backupDownloaderInstance = null;

    public function __construct(
        protected Site $site,
    ) {
        $this->http = new WordPressHttpClient($site);
    }

    // ── Backup downloader ─────────────────────────────────────────────

    public function backupDownloader(): WordPressBackupDownloader
    {
        if ($this->backupDownloaderInstance === null) {
            $this->backupDownloaderInstance = new WordPressBackupDownloader($this, $this->http);
        }

        return $this->backupDownloaderInstance;
    }

    // ── Delegated backup methods (interface contract, callers unchanged) ──

    public function chunkedDownload(string $type, string $saveTo, ?callable $onProgress = null, ?callable $onCheckCancelled = null): void
    {
        $this->backupDownloader()->chunkedDownload($type, $saveTo, $onProgress, $onCheckCancelled);
    }

    public function chunkedDownloadFilesAsChunks(string $saveTo, ?callable $onProgress = null): array
    {
        return $this->backupDownloader()->chunkedDownloadFilesAsChunks($saveTo, $onProgress);
    }

    // ── Delegated HTTP methods (traits use these) ─────────────────────

    public function setBackupMode(bool $enabled): void
    {
        $this->http->setBackupMode($enabled);
    }

    public function resetThrottle(): void
    {
        $this->http->resetThrottle();
    }

    public function request(string $method, string $endpoint, array $data = [], array $queryParams = [], int $timeout = 30): Response
    {
        return $this->http->request($method, $endpoint, $data, $queryParams, $timeout);
    }

    private function throwIfFailed(Response $response): void
    {
        $this->http->throwIfFailed($response);
    }

    public function requestRaw(string $method, string $endpoint, array $data = [], int $timeout = 30): Response
    {
        return $this->http->requestRaw($method, $endpoint, $data, $timeout);
    }

    /**
     * Get backup capabilities from the WP plugin.
     */
    public function getBackupCapabilities(): ?array
    {
        try {
            $response = $this->request('POST', '/backup/capabilities', [], [], 10);
            if (! $response->successful()) {
                return null;
            }
            $data = $response->json();

            return $data['success'] ?? false ? $data : null;
        } catch (\Illuminate\Http\Client\RequestException|\RuntimeException) {
            return null;
        }
    }

    public function streamDownloadTo(string $endpoint, array $data, string $saveTo, int $maxRetries = 5): void
    {
        $this->http->streamDownloadTo($endpoint, $data, $saveTo, $maxRetries);
    }

    public function streamDownload(string $endpoint, string $saveTo): void
    {
        $this->http->streamDownload($endpoint, $saveTo);
    }
}
