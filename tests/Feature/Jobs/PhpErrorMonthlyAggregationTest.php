<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AggregatePhpErrorsMonthly;
use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Services\RetentionPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SPEC §14.1: raw PHP errors 30 days, aggregated 13 months.
 *
 * The aggregate is what lets §12.4's sentence be written about a month whose raw
 * rows have already aged out — including WHICH plugin produced the errors, which
 * is the part that justifies the invoice.
 */
class PhpErrorMonthlyAggregationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->site = Site::factory()->create();
    }

    private function error(string $slug, string $level, int $count, string $lastSeen): void
    {
        PhpErrorLog::create([
            'site_id' => $this->site->id,
            'level' => $level,
            'message' => 'Undefined index: '.$slug.'-'.$count,
            'file' => "/wp-content/plugins/{$slug}/render.php",
            'line' => 42,
            'message_hash' => md5($slug.$level.$count.$lastSeen),
            'count' => $count,
            'source_type' => 'plugin',
            'source_slug' => $slug,
            'first_seen_at' => $lastSeen,
            'last_seen_at' => $lastSeen,
        ]);
    }

    public function test_groups_by_month_source_and_level(): void
    {
        $thisMonth = now()->startOfMonth()->addDays(2)->toDateTimeString();

        $this->error('jet-woo-builder', 'warning', 800, $thisMonth);
        $this->error('jet-woo-builder', 'warning', 47, $thisMonth);
        $this->error('jet-woo-builder', 'fatal', 3, $thisMonth);
        $this->error('elementor', 'warning', 12, $thisMonth);

        (new AggregatePhpErrorsMonthly)->handle();

        $rows = DB::table('php_error_monthly_aggregates')->get();
        $this->assertCount(3, $rows);

        $warnings = DB::table('php_error_monthly_aggregates')
            ->where('source_slug', 'jet-woo-builder')
            ->where('level', 'warning')
            ->first();

        // The 847 of "847 warnings from JetWooBuilder".
        $this->assertSame(847, (int) $warnings->occurrences);
        $this->assertSame(2, (int) $warnings->signatures);
    }

    public function test_rerunning_corrects_a_month_instead_of_doubling_it(): void
    {
        $day = now()->startOfMonth()->addDay()->toDateTimeString();

        $this->error('wpforms', 'warning', 10, $day);
        (new AggregatePhpErrorsMonthly)->handle();

        $this->error('wpforms', 'warning', 5, $day);
        (new AggregatePhpErrorsMonthly)->handle();

        $rows = DB::table('php_error_monthly_aggregates')->where('source_slug', 'wpforms')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(15, (int) $rows->first()->occurrences);
    }

    public function test_retention_matches_the_spec(): void
    {
        $this->assertSame(30, RetentionPolicyService::CATEGORIES['php_error_logs']['default']);
        $this->assertSame(396, RetentionPolicyService::CATEGORIES['php_error_aggregates']['default']);
    }
}
