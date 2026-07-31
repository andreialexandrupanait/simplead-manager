<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WordPressBackupDownloader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Why a connector call failed has to survive the trip into the database.
 *
 * florinpasat.com failed for eight consecutive nights on "Allowed memory size
 * of 536870912 bytes exhausted". What `backups.error_message` recorded was
 * "Chunk 3 exec failed: HTTP 500". The cause was in the response the whole
 * time — WordPress puts a PHP fatal at `data.error.message`, and the reader
 * only looked at `error.message`, so the `?? "HTTP {status}"` fallback won
 * every single time.
 *
 * These payloads are the real shapes, taken from the production log line that
 * finally revealed it.
 */
class WordPressBackupDownloaderErrorTest extends TestCase
{
    private function explain(?array $json, int $status = 500): string
    {
        $method = new ReflectionMethod(WordPressBackupDownloader::class, 'explainFailure');

        return $method->invoke(
            (new \ReflectionClass(WordPressBackupDownloader::class))->newInstanceWithoutConstructor(),
            $json,
            $status,
        );
    }

    public function test_a_php_fatal_is_reported_with_its_message_and_location(): void
    {
        $payload = [
            'code' => 'internal_server_error',
            'message' => '<p>A fost o eroare critică pe acest site web.</p>',
            'data' => [
                'status' => 500,
                'error' => [
                    'type' => 1,
                    'message' => 'Allowed memory size of 536870912 bytes exhausted (tried to allocate 1179648 bytes)',
                    'file' => '/var/www/site/public_html/wp-content/plugins/simplead-manager-connector/includes/endpoints/class-backup-endpoint.php',
                    'line' => 2211,
                ],
            ],
        ];

        $explained = $this->explain($payload);

        $this->assertStringContainsString('Allowed memory size', $explained);
        $this->assertStringContainsString('class-backup-endpoint.php:2211', $explained);
        $this->assertStringNotContainsString('HTTP 500', $explained);
    }

    /**
     * The path is trimmed to a basename: the absolute path leaks the client's
     * hosting layout into our database and reads as noise next to the line
     * number, which is the part that locates the bug.
     */
    public function test_the_clients_absolute_path_is_not_carried_over(): void
    {
        $explained = $this->explain([
            'data' => ['error' => [
                'message' => 'boom',
                'file' => '/var/www/185470c1-0e58-4755-b095-e8d406a92bf1/public_html/wp-content/x.php',
                'line' => 10,
            ]],
        ]);

        $this->assertStringNotContainsString('185470c1', $explained);
        $this->assertStringContainsString('x.php:10', $explained);
    }

    public function test_a_wp_error_still_reports_its_own_message(): void
    {
        $explained = $this->explain(['error' => ['message' => 'Invalid backup token']]);

        $this->assertSame('Invalid backup token', $explained);
    }

    public function test_a_bare_rest_message_is_used_when_there_is_nothing_deeper(): void
    {
        $explained = $this->explain(['message' => 'Sorry, you are not allowed to do that.'], 403);

        $this->assertSame('Sorry, you are not allowed to do that.', $explained);
    }

    /**
     * A fatal outranks the generic REST envelope: the envelope only ever says
     * "there was a critical error on this website", which is what made the
     * original bug so hard to see.
     */
    public function test_the_fatal_wins_over_the_generic_envelope(): void
    {
        $explained = $this->explain([
            'message' => '<p>A fost o eroare critică pe acest site web.</p>',
            'data' => ['error' => ['message' => 'Maximum execution time of 300 seconds exceeded']],
        ]);

        $this->assertSame('Maximum execution time of 300 seconds exceeded', $explained);
    }

    public function test_an_empty_body_falls_back_to_the_status_code(): void
    {
        $this->assertSame('HTTP 502', $this->explain(null, 502));
        $this->assertSame('HTTP 500', $this->explain([], 500));
        // A present-but-empty message must not win over the status code.
        $this->assertSame('HTTP 500', $this->explain(['error' => ['message' => '']], 500));
    }
}
