<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\FetchPhpErrorLogs;
use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SPEC §5.6 — Manager side of the own-handler error collector.
 *
 * The connector used to tail wp-content/debug.log, which the spec forbids: it is
 * off on most production sites, gets rotated by the host, and offers no way to
 * tell one recurring bug from two thousand separate lines. From connector 2.20.0
 * the site keeps its own deduplicated aggregate and Manager drains it.
 */
class PhpErrorCollectorIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $connectorVersion = '2.20.0'): Site
    {
        return Site::factory()->create([
            'is_connected' => true,
            'connector_version' => $connectorVersion,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function bindCollector(array $errors, ?array &$acknowledged = null): void
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('getPhpErrors')->willReturn(['success' => true, 'enabled' => true, 'errors' => $errors]);
        $api->method('acknowledgePhpErrors')->willReturnCallback(function (array $signatures) use (&$acknowledged): array {
            $acknowledged = $signatures;

            return ['success' => true, 'removed' => count($signatures)];
        });
        // The debug.log path must NOT be used on a 2.20.0 connector.
        $api->expects($this->never())->method('getErrorLogs');

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));
    }

    private function entry(array $overrides = []): array
    {
        return array_merge([
            'signature' => 'sig-'.uniqid('', true),
            'level' => 'warning',
            'message' => 'Undefined array key {n}',
            'file' => 'wp-content/plugins/jetwoobuilder/includes/render.php',
            'line' => 88,
            'source' => ['type' => 'plugin', 'slug' => 'jetwoobuilder'],
            'count' => 12,
            'first_seen' => now()->subHours(3)->toIso8601String(),
            'last_seen' => now()->toIso8601String(),
        ], $overrides);
    }

    public function test_an_aggregate_entry_is_stored_with_its_attribution(): void
    {
        $site = $this->site();
        $this->bindCollector([$this->entry(['signature' => 'sig-a'])]);

        (new FetchPhpErrorLogs($site))->handle();

        $this->assertDatabaseHas('php_error_logs', [
            'site_id' => $site->id,
            'message_hash' => 'sig-a',
            'count' => 12,
            'source_type' => 'plugin',
            'source_slug' => 'jetwoobuilder',
        ]);
    }

    public function test_only_what_was_stored_is_acknowledged(): void
    {
        $site = $this->site();
        $acknowledged = null;

        $this->bindCollector([
            $this->entry(['signature' => 'sig-good']),
            // No signature — unusable, so it must not be acknowledged away.
            ['level' => 'warning', 'message' => 'orphan'],
        ], $acknowledged);

        (new FetchPhpErrorLogs($site))->handle();

        $this->assertSame(['sig-good'], $acknowledged);
    }

    public function test_counts_accumulate_across_collections(): void
    {
        $site = $this->site();

        // The site resets its counter when it is acknowledged, so the second
        // window's 5 are 5 NEW occurrences of the same bug, not a restatement.
        $this->bindCollector([$this->entry(['signature' => 'sig-x', 'count' => 12])]);
        (new FetchPhpErrorLogs($site))->handle();

        $this->bindCollector([$this->entry(['signature' => 'sig-x', 'count' => 5])]);
        (new FetchPhpErrorLogs($site))->handle();

        $this->assertSame(17, PhpErrorLog::where('message_hash', 'sig-x')->firstOrFail()->count);
        $this->assertDatabaseCount('php_error_logs', 1);
    }

    public function test_a_new_fatal_raises_an_alert(): void
    {
        $site = $this->site();
        $this->bindCollector([$this->entry(['signature' => 'sig-fatal', 'level' => 'fatal'])]);

        (new FetchPhpErrorLogs($site))->handle();

        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $site->user_id]);
    }

    public function test_a_site_on_an_older_connector_still_uses_the_legacy_path(): void
    {
        $site = $this->site('2.19.2');

        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->expects($this->once())->method('getErrorLogs')->willReturn(['entries' => []]);
        $api->expects($this->never())->method('getPhpErrors');
        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));

        (new FetchPhpErrorLogs($site))->handle();
    }

    public function test_an_empty_aggregate_writes_nothing(): void
    {
        $site = $this->site();
        $this->bindCollector([]);

        (new FetchPhpErrorLogs($site))->handle();

        $this->assertDatabaseCount('php_error_logs', 0);
    }

    public function test_a_disconnected_site_is_not_contacted(): void
    {
        $site = Site::factory()->create(['is_connected' => false, 'connector_version' => '2.20.0']);

        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->expects($this->never())->method('getPhpErrors');
        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));

        (new FetchPhpErrorLogs($site))->handle();
    }
}
