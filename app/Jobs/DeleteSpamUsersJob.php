<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteUser;
use App\Services\ActivityLogger;
use App\Services\JobTracker;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteSpamUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 50;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public Site $site,
        public array $wpUserIds,
        public ?int $reassignToWpId = null,
        public int $totalOriginal = 0,
        public int $deletedSoFar = 0,
        public int $errorsSoFar = 0,
    ) {
        if ($this->totalOriginal === 0) {
            $this->totalOriginal = count($this->wpUserIds);
        }
    }

    public function trackerKey(): string
    {
        return 'spam-delete-'.$this->site->id;
    }

    public function failed(?\Throwable $exception): void
    {
        $key = $this->trackerKey();
        $message = $exception?->getMessage() ?? 'Job failed unexpectedly';

        if ($exception instanceof MaxAttemptsExceededException
            || str_contains($message, 'has been attempted too many times')
            || str_contains($message, 'exceeded the timeout')) {
            $message = 'Job timed out';
        }

        $progress = '';
        if ($this->deletedSoFar > 0 || $this->errorsSoFar > 0) {
            $progress = " (deleted {$this->deletedSoFar} of {$this->totalOriginal} before failure)";
        }

        JobTracker::fail($key, "Spam deletion failed: {$message}{$progress}");
    }

    public function handle(): void
    {
        $key = $this->trackerKey();
        $isFirstChunk = $this->deletedSoFar === 0 && $this->errorsSoFar === 0;

        if ($isFirstChunk) {
            JobTracker::start($key, "Deleting {$this->totalOriginal} spam users...");
        }

        $chunk = array_slice($this->wpUserIds, 0, self::CHUNK_SIZE);
        $remaining = array_slice($this->wpUserIds, self::CHUNK_SIZE);

        try {
            $api = app(WordPressApiServiceFactory::class)->make($this->site);

            // Build a lookup of local SiteUser records for this chunk
            $chunkIds = array_map('intval', $chunk);
            $siteUsers = SiteUser::where('site_id', $this->site->id)
                ->whereIn('wp_user_id', $chunkIds)
                ->get()
                ->keyBy('wp_user_id');

            JobTracker::progress(
                $key,
                (int) (($this->deletedSoFar + $this->errorsSoFar) / $this->totalOriginal * 100),
                'Deleting batch of '.count($chunkIds).' users ('.($this->deletedSoFar + $this->errorsSoFar)." of {$this->totalOriginal} processed)..."
            );

            $response = $api->bulkDeleteUsers($chunkIds, $this->reassignToWpId);

            $deletedList = $response['deleted'] ?? [];
            $failedList = $response['failed'] ?? [];

            foreach ($deletedList as $entry) {
                $wpUserId = (int) $entry['id'];
                $username = $entry['username'] ?? "WP user #{$wpUserId}";
                $siteUser = $siteUsers->get($wpUserId);
                $label = $siteUser
                    ? "{$siteUser->username} ({$siteUser->email})"
                    : $username;

                JobTracker::appendLog($key, "Deleted {$label}");

                if ($siteUser) {
                    $siteUser->delete();
                }
            }

            foreach ($failedList as $entry) {
                $wpUserId = (int) $entry['id'];
                $reason = $entry['reason'] ?? 'Unknown error';
                $siteUser = $siteUsers->get($wpUserId);
                $label = $siteUser
                    ? "{$siteUser->username} ({$siteUser->email})"
                    : "WP user #{$wpUserId}";

                JobTracker::appendLog($key, "Failed to delete {$label}: {$reason}");
                Log::warning("DeleteSpamUsersJob: failed to delete WP user #{$wpUserId} on site {$this->site->id}", [
                    'error' => $reason,
                ]);
            }

            $deleted = count($deletedList);
            $errors = count($failedList);
            $cumulativeDeleted = $this->deletedSoFar + $deleted;
            $cumulativeErrors = $this->errorsSoFar + $errors;

            if (count($remaining) > 0) {
                self::dispatch(
                    $this->site,
                    $remaining,
                    $this->reassignToWpId,
                    $this->totalOriginal,
                    $cumulativeDeleted,
                    $cumulativeErrors,
                );

                return;
            }

            // All chunks done
            $summary = "Deleted {$cumulativeDeleted} of {$this->totalOriginal} spam users";
            if ($cumulativeErrors > 0) {
                $summary .= " ({$cumulativeErrors} failed)";
            }

            JobTracker::complete($key, $summary);

            if ($cumulativeDeleted > 0) {
                ActivityLogger::log(
                    type: 'user',
                    severity: 'warning',
                    title: "{$cumulativeDeleted} spam user(s) deleted on {$this->site->name}",
                    site: $this->site,
                    metadata: ['action' => 'spam_bulk_delete', 'count' => $cumulativeDeleted],
                    icon: 'shield-alert',
                    url: route('sites.security.users', $this->site),
                );
            }

            cache()->forget("spam_scan_{$this->site->id}");

        } catch (\Exception $e) {
            JobTracker::fail($key, "Spam deletion failed: {$e->getMessage()}");
            Log::error("DeleteSpamUsersJob failed for site {$this->site->id}: {$e->getMessage()}");

            throw $e;
        }
    }
}
