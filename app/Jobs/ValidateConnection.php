<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CloudflareConnection;
use App\Models\GoogleConnection;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\ActivityLogger;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\CloudflareService;
use App\Services\GoogleApiService;
use App\Services\Notifications\NotificationService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * P2-64: validates ONE external connection (Google / Cloudflare / storage /
 * WordPress) in its own bounded job.
 *
 * The old ValidateExternalConnections walked every connection serially with
 * tries=1, so at fleet scale it blew past its own timeout and was SIGKILLed
 * mid-run, leaving later connections unvalidated. Fanning out per connection
 * keeps each unit small and independent: one slow token can no longer starve
 * the rest, each has its own timeout, and a failure is isolated to its own job.
 */
class ValidateConnection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TYPE_GOOGLE = 'google';

    public const TYPE_CLOUDFLARE = 'cloudflare';

    public const TYPE_STORAGE = 'storage';

    public const TYPE_WORDPRESS = 'wordpress';

    public int $timeout = 60;

    public int $tries = 2;

    public array $backoff = [30, 60];

    public function __construct(
        public string $type,
        public int $connectionId,
    ) {
        $this->onQueue('sync');
    }

    public function handle(): void
    {
        match ($this->type) {
            self::TYPE_GOOGLE => $this->validateGoogle(),
            self::TYPE_CLOUDFLARE => $this->validateCloudflare(),
            self::TYPE_STORAGE => $this->validateStorage(),
            self::TYPE_WORDPRESS => $this->validateWordPress(),
            default => Log::warning("ValidateConnection: unknown type '{$this->type}'"),
        };
    }

    private function validateGoogle(): void
    {
        $conn = GoogleConnection::find($this->connectionId);
        if (! $conn) {
            return;
        }

        try {
            new GoogleApiService($conn);
        } catch (\Throwable $e) {
            $this->recordFailure("Google: {$conn->email}", $e->getMessage());
        }
    }

    private function validateCloudflare(): void
    {
        $conn = CloudflareConnection::find($this->connectionId);
        if (! $conn) {
            return;
        }

        try {
            $service = new CloudflareService($conn);
            if (! $service->validateToken()) {
                $this->recordFailure("Cloudflare: {$conn->account_email}", 'Token validation returned false');
            }
        } catch (\Throwable $e) {
            $this->recordFailure("Cloudflare: {$conn->account_email}", $e->getMessage());
        }
    }

    private function validateStorage(): void
    {
        $dest = StorageDestination::find($this->connectionId);
        if (! $dest) {
            return;
        }

        try {
            $passed = StorageFactory::make($dest)->test();

            $dest->update([
                'last_tested_at' => now(),
                'last_test_passed' => $passed,
                'last_test_error' => $passed ? null : 'Test returned false',
            ]);

            if (! $passed) {
                $this->recordFailure("Storage: {$dest->name} ({$dest->type})", 'Connection test failed');
            }
        } catch (\Throwable $e) {
            $dest->update([
                'last_tested_at' => now(),
                'last_test_passed' => false,
                'last_test_error' => $e->getMessage(),
            ]);
            $this->recordFailure("Storage: {$dest->name} ({$dest->type})", $e->getMessage());
        }
    }

    private function validateWordPress(): void
    {
        $site = Site::find($this->connectionId);
        if (! $site) {
            return;
        }

        try {
            app(WordPressApiServiceFactory::class)->make($site)->healthCheck();
        } catch (\Throwable $e) {
            $this->recordFailure("WordPress: {$site->name}", $e->getMessage());
        }
    }

    private function recordFailure(string $name, string $error): void
    {
        ActivityLogger::log('connection_error', 'warning', "Connection failed: {$name}", $error);

        NotificationService::notifyAppEvent(
            event: 'connection_validation_failed',
            title: 'External Connection Validation Failed',
            message: "{$name} failed validation.",
            fields: [['name' => $name, 'value' => $error]],
            severity: 'warning',
        );

        Log::warning('External connection validation failed', [
            'type' => $this->type,
            'name' => $name,
            'error' => $error,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ValidateConnection job failed', [
            'type' => $this->type,
            'connection_id' => $this->connectionId,
            'exception' => $exception ? get_class($exception) : 'Unknown',
            'message' => $exception?->getMessage(),
        ]);
    }
}
