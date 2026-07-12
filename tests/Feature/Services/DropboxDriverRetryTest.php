<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\StorageDestination;
use App\Services\Backup\Storage\DropboxDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * P1-64: the Dropbox driver only retried on 401 (token refresh), so a single
 * 429 (throttle) or 5xx on one chunk aborted the entire multipart upload. Each
 * request must now retry on 429/5xx with backoff, per chunk.
 */
class DropboxDriverRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake(); // don't actually wait during backoff
    }

    private function driver(): DropboxDriver
    {
        $destination = StorageDestination::factory()->create([
            'type' => 'dropbox',
            'config' => [
                'access_token' => encrypt('access-token'),
                'base_path' => '/backups',
            ],
        ]);

        return new DropboxDriver($destination);
    }

    public function test_chunk_append_retries_on_5xx_then_succeeds(): void
    {
        Http::fake([
            'content.dropboxapi.com/2/files/upload_session/append_v2' => Http::sequence()
                ->push('upstream server error', 503)
                ->push('', 200),
        ]);

        // Would previously have thrown on the first 503, aborting the upload.
        $this->driver()->appendToUploadSession('session-id', 0, 'chunk-bytes');

        Http::assertSentCount(2);
    }

    public function test_chunk_append_retries_on_429_then_succeeds(): void
    {
        Http::fake([
            'content.dropboxapi.com/2/files/upload_session/append_v2' => Http::sequence()
                ->push(['error' => 'too_many_requests'], 429)
                ->push('', 200),
        ]);

        $this->driver()->appendToUploadSession('session-id', 0, 'chunk-bytes');

        Http::assertSentCount(2);
    }

    public function test_persistent_429_eventually_gives_up_and_throws(): void
    {
        Http::fake([
            'content.dropboxapi.com/2/files/upload_session/append_v2' => Http::response('busy', 429),
        ]);

        $this->expectException(RuntimeException::class);

        $this->driver()->appendToUploadSession('session-id', 0, 'chunk-bytes');
    }
}
