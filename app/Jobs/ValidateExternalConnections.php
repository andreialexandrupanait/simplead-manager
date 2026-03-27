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

class ValidateExternalConnections implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $failures = [];

        $failures = array_merge(
            $failures,
            $this->validateGoogleConnections(),
            $this->validateCloudflareConnections(),
            $this->validateStorageDestinations(),
            $this->validateWordPressSites(),
        );

        if (count($failures) > 0) {
            $message = count($failures).' external connection(s) failed validation: '
                .implode(', ', array_map(fn ($f) => $f['name'], $failures));

            NotificationService::notifyAppEvent(
                event: 'connection_validation_failed',
                title: 'External Connection Validation Failed',
                message: $message,
                fields: array_map(fn ($f) => ['name' => $f['name'], 'value' => $f['error']], $failures),
                severity: 'warning',
            );
        }

        Log::info('External connection validation complete', [
            'failures' => count($failures),
            'details' => $failures,
        ]);
    }

    protected function validateGoogleConnections(): array
    {
        $failures = [];

        GoogleConnection::where('is_active', true)->each(function (GoogleConnection $conn) use (&$failures) {
            try {
                new GoogleApiService($conn);
            } catch (\Exception $e) {
                $failures[] = ['name' => "Google: {$conn->email}", 'error' => $e->getMessage()];
                ActivityLogger::log('connection_error', 'warning', "Google connection failed: {$conn->email}", $e->getMessage());
            }
        });

        return $failures;
    }

    protected function validateCloudflareConnections(): array
    {
        $failures = [];

        CloudflareConnection::all()->each(function (CloudflareConnection $conn) use (&$failures) {
            try {
                $service = new CloudflareService($conn);
                if (! $service->validateToken()) {
                    $failures[] = ['name' => "Cloudflare: {$conn->account_email}", 'error' => 'Token validation returned false'];
                }
            } catch (\Exception $e) {
                $failures[] = ['name' => "Cloudflare: {$conn->account_email}", 'error' => $e->getMessage()];
                ActivityLogger::log('connection_error', 'warning', "Cloudflare connection failed: {$conn->account_email}", $e->getMessage());
            }
        });

        return $failures;
    }

    protected function validateStorageDestinations(): array
    {
        $failures = [];

        StorageDestination::where('is_active', true)
            ->where('type', '!=', 'local')
            ->each(function (StorageDestination $dest) use (&$failures) {
                try {
                    $driver = StorageFactory::make($dest);
                    $passed = $driver->test();

                    $dest->update([
                        'last_tested_at' => now(),
                        'last_test_passed' => $passed,
                        'last_test_error' => $passed ? null : 'Test returned false',
                    ]);

                    if (! $passed) {
                        $failures[] = ['name' => "Storage: {$dest->name} ({$dest->type})", 'error' => 'Connection test failed'];
                    }
                } catch (\Exception $e) {
                    $dest->update([
                        'last_tested_at' => now(),
                        'last_test_passed' => false,
                        'last_test_error' => $e->getMessage(),
                    ]);
                    $failures[] = ['name' => "Storage: {$dest->name} ({$dest->type})", 'error' => $e->getMessage()];
                    ActivityLogger::log('connection_error', 'warning', "Storage connection failed: {$dest->name}", $e->getMessage());
                }
            });

        return $failures;
    }

    protected function validateWordPressSites(): array
    {
        $failures = [];

        Site::where('is_connected', true)
            ->whereNotNull('api_key')
            ->each(function (Site $site) use (&$failures) {
                try {
                    $api = app(WordPressApiServiceFactory::class)->make($site);
                    $api->healthCheck();
                } catch (\Exception $e) {
                    $failures[] = ['name' => "WordPress: {$site->name}", 'error' => $e->getMessage()];
                }
            });

        return $failures;
    }
}
