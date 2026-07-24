<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Jobs\Audit\RunSfCrawl;
use App\Models\Audit;
use App\Models\Prospect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Faza D5: the `audit:monitor` command queues re-audits for delta comparison.
 */
class MonitorAuditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_a_re_audit_for_a_prospect(): void
    {
        Queue::fake();
        $prospect = Prospect::factory()->create();
        Audit::factory()->create(['prospect_id' => $prospect->id, 'site_id' => null, 'url' => 'https://x.ro']);

        $this->artisan('audit:monitor', ['--prospect' => $prospect->id])->assertSuccessful();

        $this->assertSame(2, Audit::query()->where('prospect_id', $prospect->id)->count());
        Queue::assertPushed(RunSfCrawl::class);
    }

    public function test_all_re_audits_the_latest_per_target(): void
    {
        Queue::fake();
        $a = Prospect::factory()->create();
        $b = Prospect::factory()->create();
        Audit::factory()->create(['prospect_id' => $a->id, 'site_id' => null]);
        Audit::factory()->create(['prospect_id' => $a->id, 'site_id' => null]); // latest for a
        Audit::factory()->create(['prospect_id' => $b->id, 'site_id' => null]);

        $this->artisan('audit:monitor', ['--all' => true])->assertSuccessful();

        // One re-audit per distinct target (a, b) → 2 new audits.
        $this->assertSame(5, Audit::query()->count());
        Queue::assertPushed(RunSfCrawl::class, 2);
    }

    public function test_it_warns_when_nothing_to_monitor(): void
    {
        Queue::fake();

        $this->artisan('audit:monitor')->assertSuccessful();

        Queue::assertNotPushed(RunSfCrawl::class);
    }
}
