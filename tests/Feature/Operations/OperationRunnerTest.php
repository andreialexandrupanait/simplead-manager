<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Jobs\RunOperationJob;
use App\Models\Site;
use App\Operations\Contracts\Operation;
use App\Operations\OperationBatch;
use App\Operations\OperationContext;
use App\Operations\OperationDuration;
use App\Operations\OperationOutcome;
use App\Operations\OperationRegistry;
use App\Operations\OperationResult;
use App\Operations\OperationRunner;
use App\Operations\Operations\PurgeCloudflareCacheOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A configurable in-test operation. Because the runner/job resolve a FRESH
 * instance per site from the container, all configuration AND the call log live
 * in static state shared across instances.
 */
class FakeOperation implements Operation
{
    /** @var array<int, array{method: string, site: int}> */
    public static array $calls = [];

    /** @var array<string, mixed> */
    public static array $config = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$config = [
            'key' => 'fake.op',
            'duration' => OperationDuration::Instant,
            'reversible' => false,
            'requiresApproval' => false,
            'skipSiteIds' => [],
            'failExecuteSiteIds' => [],
            'failVerifySiteIds' => [],
        ];
    }

    public function key(): string
    {
        return self::$config['key'];
    }

    public function durationClass(): OperationDuration
    {
        return self::$config['duration'];
    }

    public function isReversible(): bool
    {
        return self::$config['reversible'];
    }

    public function requiresApproval(): bool
    {
        return self::$config['requiresApproval'];
    }

    public function prepare(OperationContext $context): OperationResult
    {
        self::$calls[] = ['method' => 'prepare', 'site' => $context->site->id];

        if (in_array($context->site->id, self::$config['skipSiteIds'], true)) {
            return OperationResult::skipped($context->site->id, 'configured skip');
        }

        return OperationResult::success($context->site->id);
    }

    public function execute(OperationContext $context): OperationResult
    {
        self::$calls[] = ['method' => 'execute', 'site' => $context->site->id];

        if (in_array($context->site->id, self::$config['failExecuteSiteIds'], true)) {
            return OperationResult::failure($context->site->id, 'configured execute failure');
        }

        return OperationResult::success($context->site->id);
    }

    public function verify(OperationContext $context): OperationResult
    {
        self::$calls[] = ['method' => 'verify', 'site' => $context->site->id];

        if (in_array($context->site->id, self::$config['failVerifySiteIds'], true)) {
            return OperationResult::failure($context->site->id, 'configured verify failure');
        }

        return OperationResult::verified($context->site->id);
    }

    public function rollback(OperationContext $context): OperationResult
    {
        self::$calls[] = ['method' => 'rollback', 'site' => $context->site->id];

        return OperationResult::skipped($context->site->id);
    }

    /** @return array<int, string> */
    public static function methodsFor(int $siteId): array
    {
        return array_values(array_map(
            fn ($c) => $c['method'],
            array_filter(self::$calls, fn ($c) => $c['site'] === $siteId),
        ));
    }

    public static function called(string $method, int $siteId): bool
    {
        return in_array($method, self::methodsFor($siteId), true);
    }
}

class OperationRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeOperation::reset();
        // Fake the queue by default so the Site "created" hook (FetchSiteFavicon /
        // ApplyPlanToSite) neither makes real HTTP nor pollutes assertions. Tests
        // that need to drive a job call its handle() directly.
        Queue::fake();
    }

    private function runner(): OperationRunner
    {
        return app(OperationRunner::class);
    }

    /** Run a single RunOperationJob synchronously against a site. */
    private function runJobFor(Site $site, string $runId = 'run-1'): void
    {
        (new RunOperationJob(FakeOperation::class, $site->id, [], 1, $runId, OperationBatch::trackerKey($runId)))
            ->handle();
    }

    public function test_dispatches_one_job_per_selected_site(): void
    {
        $sites = Site::factory()->count(3)->create();

        $handle = $this->runner()->run(new FakeOperation, $sites, [], 1);

        Queue::assertPushed(RunOperationJob::class, 3);
        $this->assertSame('running', $handle->status);
        $this->assertSame(3, $handle->total);
    }

    public function test_run_rejects_an_empty_site_collection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runner()->run(new FakeOperation, collect(), [], 1);
    }

    public function test_phases_run_in_prepare_execute_verify_order(): void
    {
        $site = Site::factory()->create();

        $this->runJobFor($site);

        $this->assertSame(['prepare', 'execute', 'verify'], FakeOperation::methodsFor($site->id));
    }

    public function test_records_per_site_outcomes_in_the_snapshot(): void
    {
        $ok = Site::factory()->create();
        $fail = Site::factory()->create();
        $skip = Site::factory()->create();

        FakeOperation::$config['failExecuteSiteIds'] = [$fail->id];
        FakeOperation::$config['skipSiteIds'] = [$skip->id];

        OperationBatch::init('run-1', 3, 'fake.op');
        $this->runJobFor($ok);
        $this->runJobFor($fail);
        $this->runJobFor($skip);

        $snapshot = OperationBatch::snapshot('run-1');
        $this->assertSame(3, $snapshot['total']);
        $this->assertSame(3, $snapshot['done']);
        $this->assertSame(1, $snapshot['failed']);
        $this->assertSame(1, $snapshot['skipped']);
        $this->assertTrue($snapshot['complete']);

        $perSite = OperationBatch::perSite('run-1');
        $this->assertSame(OperationOutcome::Success->value, $perSite[$ok->id]);
        $this->assertSame(OperationOutcome::Failure->value, $perSite[$fail->id]);
        $this->assertSame(OperationOutcome::Skipped->value, $perSite[$skip->id]);
    }

    public function test_a_single_site_failure_does_not_abort_the_batch(): void
    {
        $a = Site::factory()->create();
        $b = Site::factory()->create();
        $c = Site::factory()->create();

        FakeOperation::$config['failExecuteSiteIds'] = [$b->id];

        OperationBatch::init('run-1', 3, 'fake.op');
        foreach ([$a, $b, $c] as $site) {
            $this->runJobFor($site);
        }

        // Every site executed despite the middle one failing.
        $this->assertTrue(FakeOperation::called('execute', $a->id));
        $this->assertTrue(FakeOperation::called('execute', $b->id));
        $this->assertTrue(FakeOperation::called('execute', $c->id));

        $snapshot = OperationBatch::snapshot('run-1');
        $this->assertSame(3, $snapshot['done']);
        $this->assertSame(1, $snapshot['failed']);
    }

    public function test_rollback_is_called_only_for_a_reversible_failed_operation(): void
    {
        $site = Site::factory()->create();
        FakeOperation::$config['reversible'] = true;
        FakeOperation::$config['failExecuteSiteIds'] = [$site->id];

        $this->runJobFor($site);

        $this->assertTrue(FakeOperation::called('rollback', $site->id));
    }

    public function test_rollback_is_not_called_when_operation_is_irreversible(): void
    {
        $site = Site::factory()->create();
        FakeOperation::$config['reversible'] = false;
        FakeOperation::$config['failExecuteSiteIds'] = [$site->id];

        $this->runJobFor($site);

        $this->assertFalse(FakeOperation::called('rollback', $site->id));
    }

    public function test_rollback_is_not_called_when_a_reversible_operation_succeeds(): void
    {
        $site = Site::factory()->create();
        FakeOperation::$config['reversible'] = true;

        $this->runJobFor($site);

        $this->assertFalse(FakeOperation::called('rollback', $site->id));
    }

    public function test_approval_gate_dispatches_nothing_until_approved(): void
    {
        $sites = Site::factory()->count(2)->create();

        FakeOperation::$config['requiresApproval'] = true;
        app(OperationRegistry::class)->register('fake.op', FakeOperation::class);

        $handle = $this->runner()->run(new FakeOperation, $sites, [], 1);

        Queue::assertNotPushed(RunOperationJob::class);
        $this->assertSame('pending_approval', $handle->status);

        $approved = $this->runner()->approve($handle->runId, 1);

        Queue::assertPushed(RunOperationJob::class, 2);
        $this->assertSame('running', $approved->status);
    }

    public function test_operation_lands_on_the_instant_queue(): void
    {
        $sites = Site::factory()->count(1)->create();
        FakeOperation::$config['duration'] = OperationDuration::Instant;

        $this->runner()->run(new FakeOperation, $sites, [], 1);

        Queue::assertPushedOn('ops-instant', RunOperationJob::class);
    }

    public function test_operation_lands_on_the_long_backups_queue(): void
    {
        $sites = Site::factory()->count(1)->create();
        FakeOperation::$config['duration'] = OperationDuration::Long;

        $this->runner()->run(new FakeOperation, $sites, [], 1);

        Queue::assertPushedOn('backups', RunOperationJob::class);
    }

    public function test_purge_cloudflare_prepare_skips_a_site_without_a_zone(): void
    {
        $site = Site::factory()->create();

        $context = new OperationContext($site, 1, [], 'run-1', OperationBatch::trackerKey('run-1'));
        $result = (new PurgeCloudflareCacheOperation)->prepare($context);

        $this->assertTrue($result->isSkipped());
        $this->assertSame($site->id, $result->siteId);
    }
}
