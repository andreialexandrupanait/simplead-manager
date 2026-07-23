<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Models\Site;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeWordPressApiService;
use Tests\TestCase;

/**
 * C-09 (wave 2): the manager-side async restore transport. When the connector
 * advertises `async_restore`, the manager kicks a detached restore and polls it
 * to completion; it falls back to the synchronous swap when the connector can't
 * go async or the kill-switch is off, and reconciles an in-flight token rather
 * than re-running the restore.
 */
class AsyncRestoreTransportTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('backups.async_restore.poll_interval_seconds', 0); // no real sleeps
        config()->set('backups.async_restore.enabled', true);
    }

    private function backup(bool $asyncCapable = true): Backup
    {
        $site = Site::factory()->create([
            'backup_capabilities' => ['staged_restore' => true, 'async_restore' => $asyncCapable],
        ]);

        return Backup::factory()->create(['site_id' => $site->id]);
    }

    /** Script the fake connector's generic request() by path. */
    private function scriptRoutes(FakeWordPressApiService $fake, array $byPath): FakeWordPressApiService
    {
        return $fake->script('request', function (string $method, string $path, array $body = [], array $headers = [], $timeout = null) use ($byPath) {
            $result = $byPath[$path] ?? ['success' => true];

            return $result instanceof Response ? $result : $result;
        });
    }

    private function invoke(RestoreBackup $job, FakeWordPressApiService $fake, string $callable, array $args): void
    {
        $m = new \ReflectionMethod($job, $callable);
        $m->setAccessible(true);
        $m->invoke($job, $fake, ...$args);
    }

    private function paths(FakeWordPressApiService $fake): array
    {
        return array_map(fn ($c) => $c['args'][1] ?? null, $fake->callsTo('request'));
    }

    public function test_async_path_kicks_and_polls_to_done(): void
    {
        $fake = $this->scriptRoutes($this->fakeApi(), [
            '/backup/restore-async' => ['async' => true, 'token' => self::TOKEN],
            '/backup/restore-status' => ['status' => 'done', 'progress' => 100],
        ]);

        $this->invoke(new RestoreBackup($this->backup()), $fake, 'sendRestoreAsync', ['files', 'http://x/dl', 'staged']);

        $paths = $this->paths($fake);
        $this->assertContains('/backup/restore-async', $paths);
        $this->assertContains('/backup/restore-status', $paths);
        $this->assertNotContains('/backup/restore', $paths, 'the synchronous endpoint must not be used');
    }

    public function test_falls_back_to_sync_when_connector_cannot_dispatch_async(): void
    {
        $fake = $this->scriptRoutes($this->fakeApi(), [
            '/backup/restore-async' => ['async' => false, 'reason' => 'no loopback/cron'],
            '/backup/restore' => ['success' => true],
        ]);

        $this->invoke(new RestoreBackup($this->backup()), $fake, 'sendRestoreAsync', ['database', 'http://x/dl', 'staged']);

        $this->assertContains('/backup/restore', $this->paths($fake), 'must fall back to the synchronous swap');
    }

    public function test_a_failed_connector_task_throws(): void
    {
        $fake = $this->scriptRoutes($this->fakeApi(), [
            '/backup/restore-async' => ['async' => true, 'token' => self::TOKEN],
            '/backup/restore-status' => ['status' => 'failed', 'error' => 'import blew up'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->invoke(new RestoreBackup($this->backup()), $fake, 'sendRestoreAsync', ['database', 'http://x/dl', 'staged']);
    }

    public function test_reconciles_an_in_flight_token_instead_of_re_running(): void
    {
        $backup = $this->backup();
        // A prior attempt left a token in the cache; the connector already finished.
        Cache::put("restore-async:{$backup->id}:files", self::TOKEN, 7200);

        $fake = $this->scriptRoutes($this->fakeApi(), [
            '/backup/restore-status' => ['status' => 'done'],
            '/backup/restore-async' => ['async' => true, 'token' => 'should-not-be-used'],
        ]);

        $this->invoke(new RestoreBackup($backup), $fake, 'sendRestoreAsync', ['files', 'http://x/dl', 'staged']);

        // Reconciled off the existing token — never re-kicked the restore.
        $this->assertNotContains('/backup/restore-async', $this->paths($fake));
        $this->assertNull(Cache::get("restore-async:{$backup->id}:files"));
    }

    public function test_expired_task_is_reported_and_throws(): void
    {
        $fake = $this->scriptRoutes($this->fakeApi(), [
            '/backup/restore-async' => ['async' => true, 'token' => self::TOKEN],
            '/backup/restore-status' => new Response(new Psr7Response(404, [], json_encode(['success' => false]))),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->invoke(new RestoreBackup($this->backup()), $fake, 'sendRestoreAsync', ['files', 'http://x/dl', 'staged']);
    }

    public function test_kill_switch_off_uses_the_sync_path(): void
    {
        config()->set('backups.async_restore.enabled', false);
        $job = new RestoreBackup($this->backup(asyncCapable: true));

        $m = new \ReflectionMethod($job, 'shouldUseAsyncRestore');
        $m->setAccessible(true);
        $this->assertFalse($m->invoke($job), 'the kill-switch must force the sync path even when the connector supports async');
    }

    public function test_capability_absent_uses_the_sync_path(): void
    {
        $job = new RestoreBackup($this->backup(asyncCapable: false));

        $m = new \ReflectionMethod($job, 'shouldUseAsyncRestore');
        $m->setAccessible(true);
        $this->assertFalse($m->invoke($job));
    }
}
