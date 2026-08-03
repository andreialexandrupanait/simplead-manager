<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Orchestration;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\Orchestration\DbDumpBudget;
use App\Backup\V2\Plugin\DownloadedChunk;
use App\Backup\V2\Plugin\PluginClient;
use App\Backup\V2\Storage\ObjectLayout;
use App\Models\BackupConfig;
use App\Models\Site;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The database dump has to finish inside one request — the consistency guarantee is a single
 * transaction on a single connection, held to the last row — so the time budget is a hard edge and
 * not a tuning knob.
 *
 * `low_impact` allows twenty seconds. That is right for most sites and hopeless for a large
 * WooCommerce, and the failure it produced was the bad kind: the same backup failing at the same
 * point every night, reporting a consistency error, on a site where nothing was wrong.
 */
#[Group('backup-v2')]
class DbDumpBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ladder_climbs_and_stops_at_the_ceiling(): void
    {
        $this->assertSame([20, 60, 90], DbDumpBudget::ladder(20, 90));

        // Already at the ceiling: one attempt, not a pointless retry of the same number.
        $this->assertSame([90], DbDumpBudget::ladder(90, 90));

        // A remembered budget above the ceiling is still bounded by it.
        $this->assertSame([90], DbDumpBudget::ladder(500, 90));
    }

    public function test_the_request_outlasts_the_budget(): void
    {
        // The plugin stops when the budget is spent and then still has to finish the last segment.
        // A timeout equal to the budget cuts it off during exactly that, turning a dump that worked
        // into a transport error.
        $this->assertGreaterThan(90, DbDumpBudget::requestTimeoutFor(90));
    }

    public function test_a_dump_that_runs_out_of_time_is_given_more_rather_than_failed(): void
    {
        [$session, $client] = $this->sessionWithDumpsFinishingAt(60);

        $this->runner($session, $client)->run();

        $this->assertSame([20, 60], $client->budgetsTried, 'it should retry with more time, not give up');
    }

    public function test_what_worked_is_remembered_so_tomorrow_does_not_pay_for_it_again(): void
    {
        [$session, $client] = $this->sessionWithDumpsFinishingAt(60);

        $this->runner($session, $client)->run();

        $this->assertSame(
            60,
            BackupConfig::where('site_id', $session->site_id)->value('db_time_budget_seconds'),
            'the site should start from the budget that worked',
        );
    }

    public function test_a_remembered_budget_is_used_from_the_first_attempt(): void
    {
        [$session, $client] = $this->sessionWithDumpsFinishingAt(60);
        BackupConfig::where('site_id', $session->site_id)->update(['db_time_budget_seconds' => 60]);

        $this->runner($session, $client)->run();

        $this->assertSame([60], $client->budgetsTried, 'no rediscovery at the site\'s expense');
    }

    public function test_a_per_site_budget_above_the_default_ceiling_is_honoured(): void
    {
        // The default ceiling exists for Cloudflare's 100-second cutoff. A per-site budget above
        // it is an explicit statement that this host is not behind such a proxy — clamping it back
        // to the ceiling made the documented lever silently do nothing.
        [$session, $client] = $this->sessionWithDumpsFinishingAt(240);
        BackupConfig::where('site_id', $session->site_id)->update(['db_time_budget_seconds' => 240]);

        $this->runner($session, $client)->run();

        $this->assertSame([240], $client->budgetsTried, 'the explicit budget, not the ceiling');
    }

    public function test_a_database_that_will_not_finish_says_so_in_numbers(): void
    {
        // Never finishes, at any budget. The message has to name what happened — the old one said
        // "done=false, consistent=false", which reads like the data is broken.
        [$session, $client] = $this->sessionWithDumpsFinishingAt(PHP_INT_MAX);

        $this->runner($session, $client)->run();

        $session->refresh();
        $message = (string) $session->error_message;

        $this->assertStringContainsString('did not finish dumping', $message);
        $this->assertStringContainsString('4 of 12 tables', $message, 'how far it got');
        $this->assertStringContainsString('db_time_budget_seconds', $message, 'and what to do about it');
    }

    /**
     * @return array{0: BackupSession, 1: BudgetRecordingClient}
     */
    private function sessionWithDumpsFinishingAt(int $needed): array
    {
        $site = Site::factory()->create();
        BackupConfig::factory()->create(['site_id' => $site->id]);

        $session = BackupSession::create([
            'site_id' => $site->id,
            'type' => 'full',
            'scope' => ['database' => true, 'files' => false],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Requested,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'checkpoint' => [],
            'idempotency_key' => 'budget-'.uniqid('', true),
            'format_version' => 'simplead-backup/2',
        ]);

        return [$session, new BudgetRecordingClient($needed)];
    }

    private function runner(BackupSession $session, PluginClient $client): BackupRunner
    {
        $handler = new MockHandler;
        for ($i = 0; $i < 40; $i++) {
            $handler->append(new Result([]));
        }

        return new BackupRunner(
            session: $session,
            client: $client,
            s3: new S3Client([
                'version' => 'latest',
                'region' => 'us-east-1',
                'credentials' => ['key' => 'x', 'secret' => 'y'],
                'handler' => $handler,
            ]),
            bucket: 'irrelevant',
            layout: new ObjectLayout(1, (int) $session->site_id, (int) $session->id, 'test/{client_id}/{site_id}/{backup_id}'),
        );
    }
}

/**
 * A host whose database needs a given number of seconds: anything less and the dump stops cleanly,
 * part-way, exactly as the real dumper does when the budget runs out.
 */
final class BudgetRecordingClient implements PluginClient
{
    /** @var list<int> */
    public array $budgetsTried = [];

    public function __construct(private readonly int $secondsNeeded) {}

    public function capabilities(): array
    {
        return [
            'plugin' => ['version' => '0.7.1'],
            'wordpress' => ['version' => '6.9'],
            'php' => ['version' => PHP_VERSION],
            'extensions' => ['zip' => true],
            'disk' => ['temp_dir' => '/tmp/sam_backup', 'temp_writable' => true, 'free_bytes' => 8 * 1024 ** 3],
            'database' => ['consistent_snapshot_supported' => true, 'server_version' => 'fake', 'engine_family' => 'mysql', 'non_innodb_tables' => []],
        ];
    }

    public function filesInventory(string $sessionId, array $rules, array $options = []): array
    {
        return ['ok' => true, 'session_id' => $sessionId, 'chunk_count' => 0, 'total_files' => 0, 'total_bytes' => 0];
    }

    public function dbDump(string $sessionId, array $params = []): array
    {
        $budget = (int) ($params['time_budget'] ?? 0);
        $this->budgetsTried[] = $budget;

        $done = $budget >= $this->secondsNeeded;

        return [
            'ok' => true,
            'done' => $done,
            'consistent' => true,
            'database' => 'wp',
            'server_version' => 'fake',
            'engine' => 'mysql',
            'table_count' => 12,
            'total_rows' => $done ? 100000 : 4200,
            'tables' => $done
                ? array_fill(0, 12, ['name' => 't', 'rows' => 1])
                : array_fill(0, 4, ['name' => 't', 'rows' => 1]),
            'segments' => $done ? [] : [],
        ];
    }

    public function dbChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk
    {
        throw new \RuntimeException('no segments in this fake');
    }

    public function filesChunkExec(string $sessionId, int $chunkIndex): array
    {
        return ['ok' => true, 'files' => 0, 'bytes' => 0];
    }

    public function filesChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk
    {
        throw new \RuntimeException('no chunks in this fake');
    }
}
