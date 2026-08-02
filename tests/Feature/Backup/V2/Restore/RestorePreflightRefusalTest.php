<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Restore;

use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\ManifestReader;
use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Enums\RestoreSessionState as R;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Restore\RestoreClient;
use App\Backup\V2\Restore\RestoreRunner;
use App\Backup\V2\Storage\ObjectLayout;
use App\Models\Site;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;

/**
 * The refusal has to land BEFORE the transfer, which is the only reason it is worth having.
 *
 * A restore that discovers there is no room does so at the worst moment: the site is in maintenance,
 * half its files have been swapped, and the rollback that would put them back needs space of its
 * own. Everything this test asserts is about ordering — the host is never asked to prepare, no chunk
 * is ever pushed, and the site is untouched.
 */
#[Group('restore')]
class RestorePreflightRefusalTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    public function test_a_restore_that_does_not_fit_is_refused_before_anything_is_sent(): void
    {
        $client = new RecordingRestoreClient(freeBytes: 1024 ** 3); // 1 GB free

        $restore = $this->runRestore($client);

        $this->assertSame(R::Failed, $restore->state);
        $this->assertSame(BackupErrorCode::DiskFull->value, $restore->error_code);
        $this->assertStringContainsString('Not enough disk space', (string) $restore->error_message);

        $this->assertSame([], $client->calls, 'the host must not be asked to do anything it cannot finish');
    }

    public function test_a_host_with_room_gets_as_far_as_preparing_the_restore(): void
    {
        $client = new RecordingRestoreClient(freeBytes: 200 * 1024 ** 3);

        $restore = $this->runRestore($client);

        $this->assertContains('prepare', $client->calls);
        $this->assertNotSame(BackupErrorCode::DiskFull->value, (string) $restore->error_code);

        // And what it decided is on the record, so a refusal can be argued with using numbers.
        $disk = $restore->checkpoint['disk_preflight'] ?? [];
        $this->assertTrue((bool) ($disk['checked'] ?? false));
        $this->assertSame(8 * 1024 ** 3, $disk['file_bytes'] ?? null);
    }

    private function runRestore(RecordingRestoreClient $client): RestoreSession
    {
        $site = Site::factory()->create();
        $backup = $this->makeBackupSession($site, ['type' => 'full', 'state' => BackupSessionState::Completed]);

        $restore = RestoreSession::create([
            'site_id' => $site->id,
            'backup_session_id' => $backup->id,
            'mode' => 'safe_merge',
            'scope' => ['database' => false, 'files' => true],
            'state' => R::Requested,
            'checkpoint' => [],
            'idempotency_key' => 'preflight-'.uniqid('', true),
        ]);

        $layoutFor = static fn (BackupSession $b): ObjectLayout => new ObjectLayout(
            1, (int) $b->site_id, (int) $b->id, 'test/{client_id}/{site_id}/{backup_id}',
        );

        (new RestoreRunner(
            session: $restore,
            client: $client,
            s3: $this->s3ThatSaysEverythingIsThere(),
            bucket: 'irrelevant',
            resolver: new ChainResolver,
            reader: new EightGigabyteManifest,
            layoutFor: $layoutFor,
            preRestoreBackup: null,
            healthCheck: fn (): bool => true,
            sleeper: static fn (int $seconds) => \Illuminate\Support\Carbon::setTestNow(now()->addSeconds($seconds)),
        ))->run();

        return $restore->fresh();
    }

    /** Head/Get always succeed: the completeness check is not what is under test here. */
    private function s3ThatSaysEverythingIsThere(): S3Client
    {
        $handler = new MockHandler;
        for ($i = 0; $i < 20; $i++) {
            $handler->append(new Result([]));
        }

        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'x', 'secret' => 'y'],
            'handler' => $handler,
        ]);
    }
}

/** A restore point of 8 GB in two chunks of 4 GB each. */
final class EightGigabyteManifest implements ManifestReader
{
    public function read(BackupSession $session): array
    {
        $gb = 1024 ** 3;

        return [
            'format_version' => 'simplead-backup/2',
            'objects' => [
                ['kind' => 'files', 'key' => 'chunk-a', 'chunk_index' => 0, 'size' => 4 * $gb],
                ['kind' => 'files', 'key' => 'chunk-b', 'chunk_index' => 1, 'size' => 4 * $gb],
            ],
            'files' => [
                'included' => [
                    ['p' => 'wp-content/uploads/big-a.bin', 's' => 4 * $gb, 'key' => 'chunk-a', 'src' => $session->id],
                    ['p' => 'wp-content/uploads/big-b.bin', 's' => 4 * $gb, 'key' => 'chunk-b', 'src' => $session->id],
                ],
                'tombstones' => [],
            ],
        ];
    }
}

/**
 * Records what the host was asked to do, so the test can assert on what did NOT happen.
 */
final class RecordingRestoreClient implements RestoreClient
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly int $freeBytes) {}

    public function capabilities(): array
    {
        return ['disk' => [
            'temp_dir' => '/tmp/sam_backup',
            'free_bytes' => $this->freeBytes,
            'site_dir' => '/var/www/html',
            'site_free_bytes' => $this->freeBytes,
            'same_filesystem' => true,
        ]];
    }

    public function restorePrepare(string $token, array $opts): array
    {
        $this->calls[] = 'prepare';

        return ['ok' => true];
    }

    public function restoreStageChunk(string $token, string $kind, int $seq, string $localPath): array
    {
        $this->calls[] = 'stage';

        return ['ok' => true];
    }

    public function restoreApply(string $token): array
    {
        $this->calls[] = 'apply';

        return ['ok' => true, 'applied' => true];
    }

    public function restoreSupportsAsync(): bool
    {
        return false;
    }

    public function restoreApplyAsync(string $token): array
    {
        $this->calls[] = 'apply-async';

        return ['async' => false];
    }

    public function restoreCommit(string $token): array
    {
        $this->calls[] = 'commit';

        return ['ok' => true];
    }

    public function restoreRollback(string $token): array
    {
        $this->calls[] = 'rollback';

        return ['ok' => true];
    }

    public function restoreStatus(string $token): array
    {
        return ['state' => 'applied'];
    }
}
