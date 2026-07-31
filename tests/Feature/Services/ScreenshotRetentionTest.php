<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\RetentionCleanup;
use App\Models\SafeUpdate;
use App\Models\Site;
use App\Services\RetentionPolicyService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SPEC §7.4 etapa 2 / §14.1: "Capturi de ecran — ultimele 3 seturi per site".
 *
 * Every safe update captures a before/after pair and writes it to the public disk.
 * Nothing ever deleted them: ScreenshotService::cleanup() had no callers, and the
 * `system` retention category drops the safe_updates row after ~90 days, which
 * removed the only pointer to the files and orphaned the JPEGs permanently.
 */
class ScreenshotRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();  // JobTracker writes progress to Redis
        Queue::fake(); // keep Site-creation side-effect jobs off the sync queue
        Storage::fake('public');
    }

    private function runCleanup(): void
    {
        (new RetentionCleanup)->handle(
            app(RetentionPolicyService::class),
            app(SettingsService::class),
        );
    }

    private function writeSet(int $siteId, int $safeUpdateId): void
    {
        foreach (['before', 'after'] as $label) {
            Storage::disk('public')->put(
                "update-screenshots/{$siteId}/{$safeUpdateId}/{$label}.jpg",
                'jpeg-bytes'
            );
        }
    }

    public function test_keeps_the_last_three_sets_and_deletes_the_rest(): void
    {
        $site = Site::factory()->create();

        $ids = [];
        foreach (range(1, 5) as $i) {
            $update = SafeUpdate::factory()->create([
                'site_id' => $site->id,
                'status' => 'completed',
                'completed_at' => now()->subDays(6 - $i),
                'screenshot_before_path' => 'placeholder',
                'screenshot_after_path' => 'placeholder',
            ]);
            $this->writeSet($site->id, $update->id);
            $ids[] = $update->id;
        }

        $this->runCleanup();

        $kept = array_slice($ids, -3);
        $dropped = array_slice($ids, 0, 2);

        foreach ($kept as $id) {
            Storage::disk('public')->assertExists("update-screenshots/{$site->id}/{$id}/before.jpg");
            Storage::disk('public')->assertExists("update-screenshots/{$site->id}/{$id}/after.jpg");
        }

        foreach ($dropped as $id) {
            Storage::disk('public')->assertMissing("update-screenshots/{$site->id}/{$id}/before.jpg");
            Storage::disk('public')->assertMissing("update-screenshots/{$site->id}/{$id}/after.jpg");

            // The pointers must go with the files, so the UI never offers a dead link.
            $this->assertDatabaseHas('safe_updates', [
                'id' => $id,
                'screenshot_before_path' => null,
                'screenshot_after_path' => null,
            ]);
        }
    }

    public function test_the_limit_is_per_site_not_global(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        foreach ([$siteA, $siteB] as $site) {
            foreach (range(1, 2) as $i) {
                $update = SafeUpdate::factory()->create([
                    'site_id' => $site->id,
                    'status' => 'completed',
                    'completed_at' => now()->subDays($i),
                ]);
                $this->writeSet($site->id, $update->id);
            }
        }

        $this->runCleanup();

        // Two sets each is under the limit on both sites: nothing may be deleted.
        foreach (Storage::disk('public')->directories('update-screenshots') as $siteDirectory) {
            $this->assertCount(2, Storage::disk('public')->directories($siteDirectory));
        }
    }

    public function test_orphaned_files_are_pruned_even_when_the_row_is_gone(): void
    {
        $site = Site::factory()->create();

        // The `system` category deletes safe_updates rows after ~90 days; the files
        // it leaves behind must still be reachable by the pruner.
        foreach ([101, 102, 103, 104] as $safeUpdateId) {
            $this->writeSet($site->id, $safeUpdateId);
        }

        $this->runCleanup();

        Storage::disk('public')->assertMissing("update-screenshots/{$site->id}/101/before.jpg");
        Storage::disk('public')->assertExists("update-screenshots/{$site->id}/104/before.jpg");
    }
}
