<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Models\UpdateLog;
use App\Services\ReportChartService;
use App\Services\Reports\Sections\ErrorLogGatherer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SPEC §12.4 — "cifra care justifică factura":
 *
 *   "Am identificat 847 de avertismente generate de JetWooBuilder 2.1.3. Am
 *    aplicat versiunea 2.1.4 pe 18 iulie. Erori active în prezent: 0."
 *
 * Every ingredient existed and never met. The connector attributes each error to
 * a plugin (§5.6) and update_logs records what was applied, but the gatherer
 * emitted only raw counters — dropping the attribution exactly where the report
 * needed it. The one section that demonstrates preventive work said nothing about
 * the work.
 */
class ErrorNarrativeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->site = Site::factory()->create();
    }

    private function gather(): array
    {
        return (new ErrorLogGatherer)->gather(
            $this->site,
            now()->startOfMonth(),
            now()->endOfMonth(),
            null,
            null,
            app(ReportChartService::class),
            'ro',
        );
    }

    private function error(string $slug, int $count, string $lastSeen, string $level = 'warning'): void
    {
        PhpErrorLog::create([
            'site_id' => $this->site->id,
            'level' => $level,
            'message' => 'Undefined array key in '.$slug.' at '.$lastSeen,
            'file' => "/wp-content/plugins/{$slug}/render.php",
            'line' => 12,
            'message_hash' => md5($slug.$count.$lastSeen),
            'count' => $count,
            'source_type' => 'plugin',
            'source_slug' => $slug,
            'first_seen_at' => $lastSeen,
            'last_seen_at' => $lastSeen,
        ]);
    }

    public function test_the_narrative_names_the_plugin_the_version_and_what_is_left(): void
    {
        $updatedAt = now()->startOfMonth()->addDays(17);

        $this->error('jet-woo-builder', 800, now()->startOfMonth()->addDays(3)->toDateTimeString());
        $this->error('jet-woo-builder', 47, now()->startOfMonth()->addDays(10)->toDateTimeString());

        UpdateLog::create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'jet-woo-builder',
            'name' => 'JetWooBuilder',
            'from_version' => '2.1.3',
            'to_version' => '2.1.4',
            'success' => true,
            'performed_at' => $updatedAt,
        ]);

        $narratives = $this->gather()['narratives'];

        $this->assertCount(1, $narratives);
        $this->assertSame('JetWooBuilder', $narratives[0]['name']);
        $this->assertSame(847, $narratives[0]['occurrences']);
        $this->assertSame('2.1.4', $narratives[0]['updated_to']);
        // Nothing seen after the update, so the closing number is honest zero.
        $this->assertSame(0, $narratives[0]['still_active']);
    }

    /**
     * The report must never imply the fix worked when errors kept coming. This is
     * the whole reason still_active is measured after the update rather than
     * assumed to be zero.
     */
    public function test_errors_after_the_update_are_still_counted_as_active(): void
    {
        $updatedAt = now()->startOfMonth()->addDays(5);

        $this->error('elementor', 300, now()->startOfMonth()->addDays(2)->toDateTimeString());
        $this->error('elementor', 120, now()->startOfMonth()->addDays(9)->toDateTimeString());

        UpdateLog::create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'elementor',
            'name' => 'Elementor',
            'from_version' => '3.22',
            'to_version' => '3.23',
            'success' => true,
            'performed_at' => $updatedAt,
        ]);

        $narratives = $this->gather()['narratives'];

        $this->assertSame(120, $narratives[0]['still_active']);
    }

    public function test_a_handful_of_errors_is_not_a_paragraph(): void
    {
        $this->error('some-plugin', 3, now()->startOfMonth()->addDay()->toDateTimeString());

        $this->assertSame([], $this->gather()['narratives']);
    }

    public function test_unattributed_errors_produce_no_narrative(): void
    {
        PhpErrorLog::create([
            'site_id' => $this->site->id,
            'level' => 'warning',
            'message' => 'Something, somewhere',
            'message_hash' => md5('anon'),
            'count' => 500,
            'source_type' => 'unknown',
            'source_slug' => null,
            'first_seen_at' => now()->startOfMonth()->addDay(),
            'last_seen_at' => now()->startOfMonth()->addDay(),
        ]);

        // Naming no one is better than naming the wrong plugin in a client report.
        $this->assertSame([], $this->gather()['narratives']);
    }
}
