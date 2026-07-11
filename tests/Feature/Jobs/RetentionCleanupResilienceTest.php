<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RetentionCleanup;
use App\Models\Site;
use App\Services\RetentionPolicyService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RetentionCleanupResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // JobTracker writes progress to Redis; keep the test hermetic.
        Redis::spy();
    }

    public function test_dropped_security_commands_table_is_no_longer_referenced(): void
    {
        $tables = [];
        foreach (RetentionPolicyService::CATEGORIES as $config) {
            foreach ($config['tables'] as $tableConfig) {
                $tables[] = $tableConfig['table'];
            }
        }

        $this->assertNotContains(
            'security_commands',
            $tables,
            'security_commands was dropped by migration 2026_07_11_000004 and must not be pruned.'
        );
    }

    public function test_missing_table_does_not_abort_the_nightly_run(): void
    {
        $site = Site::factory()->create();

        // A row in a LATE category (SEO is the final category) that must be pruned.
        $oldAuditId = DB::table('seo_audits')->insertGetId([
            'site_id' => $site->id,
            'status' => 'completed',
            'created_at' => now()->subDays(400),
            'updated_at' => now()->subDays(400),
        ]);

        // Simulate the P0-01 crash condition: a table listed in an EARLY
        // category is gone (as security_commands was in production). Uptime is
        // the first category iterated, so if a missing table aborted the run,
        // the SEO category below would never prune.
        DB::statement('DROP TABLE IF EXISTS uptime_checks CASCADE');

        (new RetentionCleanup('scheduled'))->handle(
            app(RetentionPolicyService::class),
            app(SettingsService::class),
        );

        // The run completed end-to-end (later categories still pruned)...
        $this->assertDatabaseMissing('seo_audits', ['id' => $oldAuditId]);

        // ...and it persisted a completion timestamp rather than dying mid-run.
        $this->assertNotNull(app(SettingsService::class)->get('retention_last_run_at'));
    }

    public function test_category_stats_survive_a_missing_table(): void
    {
        // The Settings retention panel queries per-table stats; a dropped table
        // must not throw and blank the whole panel.
        DB::statement('DROP TABLE IF EXISTS uptime_checks CASCADE');

        $stats = app(RetentionPolicyService::class)->getCategoryStats('uptime');

        // Missing table is skipped gracefully, no exception thrown.
        $this->assertSame([], $stats);
    }
}
