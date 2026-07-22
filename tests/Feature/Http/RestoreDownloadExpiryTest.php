<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * C-07 (Faza C): /restore-download/{token} tokens must expire. The legitimate
 * window is the single connector fetch inside the restore POST (≤30 min); a
 * worker killed between staging and cleanup used to leave the archive
 * downloadable by anyone with the token until the 24h temp sweep.
 */
class RestoreDownloadExpiryTest extends TestCase
{
    private string $token;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = bin2hex(random_bytes(32));
        $this->path = storage_path("app/temp/restore-{$this->token}");

        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_fresh_token_downloads_the_staged_archive(): void
    {
        file_put_contents($this->path, 'archive-bytes');

        $this->get("/restore-download/{$this->token}")
            ->assertOk();
    }

    public function test_expired_token_is_rejected_and_the_file_is_removed(): void
    {
        file_put_contents($this->path, 'archive-bytes');
        touch($this->path, now()->subMinutes(46)->getTimestamp());

        $this->get("/restore-download/{$this->token}")
            ->assertNotFound();

        $this->assertFileDoesNotExist($this->path);
    }

    public function test_token_inside_the_window_is_still_valid(): void
    {
        file_put_contents($this->path, 'archive-bytes');
        touch($this->path, now()->subMinutes(44)->getTimestamp());

        $this->get("/restore-download/{$this->token}")
            ->assertOk();
    }

    public function test_malformed_token_is_rejected(): void
    {
        $this->get('/restore-download/not-a-token')
            ->assertNotFound();
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->get('/restore-download/'.bin2hex(random_bytes(32)))
            ->assertNotFound();
    }
}
