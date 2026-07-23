<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\AuditRunStatus;
use App\Enums\AuditStatus;
use App\Enums\CrawlSource;
use App\Jobs\Audit\RunSfCrawl;
use App\Models\Audit;
use App\Services\Audit\ScreamingFrogCrawlRunner;
use App\Services\Audit\SfCrawlLoader;
use App\Services\Audit\SfCrawlRunner;
use App\Services\Audit\SfExportRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Faza D (D2b): the SF crawl job — runs the crawl (via an injected runner, never
 * real SF), ingests the exports into a manifest, and tracks it on an audit_run.
 */
class RunSfCrawlTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_DIR = __DIR__.'/../../Fixtures/Audit/crawl-sample';

    private string $tmpBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBase = sys_get_temp_dir().'/audit-crawls-'.bin2hex(random_bytes(6));
        config(['audit.crawls_base_dir' => $this->tmpBase]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpBase);
        parent::tearDown();
    }

    public function test_build_sf_args_carries_the_exact_57_2_5_export_set(): void
    {
        $args = ScreamingFrogCrawlRunner::buildSfArgs('https://x.ro', '/out');

        $this->assertSame('--crawl', $args[0]);
        $this->assertSame('https://x.ro', $args[1]);
        $this->assertContains('--headless', $args);
        $this->assertContains('--skip-empty', $args);
        $this->assertNotContains('--config', $args); // default config is validated

        $tabs = $args[array_search('--export-tabs', $args, true) + 1];
        $this->assertCount(57, explode(',', $tabs));
        $bulk = $args[array_search('--bulk-export', $args, true) + 1];
        $this->assertSame(implode(',', SfExportRegistry::BULK_EXPORTS), $bulk);
    }

    public function test_headless_crawl_records_a_done_run_and_moves_audit_to_colectare(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::Configurat]);
        $runner = $this->fakeRunner();

        (new RunSfCrawl($audit->id))->handle($runner, new SfCrawlLoader);

        $this->assertTrue($runner->called);
        $this->assertSame(AuditStatus::Colectare, $audit->fresh()->status);

        $run = $audit->runs()->firstOrFail();
        $this->assertSame(AuditRunStatus::Done, $run->status);
        $this->assertSame(CrawlSource::SfHeadless, $run->source);
        $this->assertNotEmpty($run->log);
        // 5 fixtures resolve to requested labels; 1 is an unmatched stray.
        $this->assertEqualsCanonicalizing(
            ['present' => 5, 'absent' => 59, 'unmatched' => 1, 'total' => 64],
            $run->manifest,
        );
    }

    public function test_manual_upload_source_ingests_without_running_the_crawler(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::Configurat]);
        // Pre-populate the folder as a manual upload would.
        $folder = $this->tmpBase.'/audit_'.$audit->id;
        File::ensureDirectoryExists($folder);
        foreach (File::glob(self::FIXTURE_DIR.'/*.csv') as $f) {
            File::copy($f, $folder.'/'.basename($f));
        }
        $runner = $this->fakeRunner();

        (new RunSfCrawl($audit->id, CrawlSource::ManualUpload))->handle($runner, new SfCrawlLoader);

        $this->assertFalse($runner->called, 'The crawler must not run for a manual upload.');
        $run = $audit->runs()->firstOrFail();
        $this->assertSame(AuditRunStatus::Done, $run->status);
        $this->assertSame(5, $run->manifest['present']);
    }

    public function test_a_crawl_failure_marks_the_run_failed_and_propagates(): void
    {
        $audit = Audit::factory()->create(['status' => AuditStatus::Configurat]);
        $runner = $this->fakeRunner(fail: true);

        try {
            (new RunSfCrawl($audit->id))->handle($runner, new SfCrawlLoader);
            $this->fail('The crawl failure must propagate so the job fails.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SF boom', $e->getMessage());
        }

        $run = $audit->runs()->firstOrFail();
        $this->assertSame(AuditRunStatus::Failed, $run->status);
        $this->assertStringContainsString('SF boom', (string) $run->error);
    }

    public function test_it_guards_a_single_crawl_with_without_overlapping(): void
    {
        $middleware = (new RunSfCrawl(1))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    private function fakeRunner(bool $fail = false): SfCrawlRunner
    {
        return new class($fail) implements SfCrawlRunner
        {
            public bool $called = false;

            public function __construct(private bool $fail) {}

            public function crawl(string $url, string $outputFolder): void
            {
                $this->called = true;
                if ($this->fail) {
                    throw new \RuntimeException('SF boom');
                }
                foreach (glob(RunSfCrawlTest::fixtureDir().'/*.csv') ?: [] as $f) {
                    copy($f, $outputFolder.'/'.basename($f));
                }
            }
        };
    }

    public static function fixtureDir(): string
    {
        return self::FIXTURE_DIR;
    }
}
