<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\SafeUpdate;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecoverStuckSafeUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function staleSafeUpdate(string $status = 'updating'): SafeUpdate
    {
        $site = Site::factory()->create();
        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'status' => $status,
        ]);

        DB::table('safe_updates')->where('id', $safeUpdate->id)->update([
            'updated_at' => now()->subMinutes(45),
        ]);

        return $safeUpdate->fresh();
    }

    public function test_stuck_safe_update_is_swept_to_failed(): void
    {
        $safeUpdate = $this->staleSafeUpdate('updating');

        $this->artisan('safe-updates:recover-stuck')->assertSuccessful();

        $fresh = $safeUpdate->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('worker died', (string) $fresh->error_message);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_all_intermediate_states_are_swept(): void
    {
        $states = ['pending', 'backing_up', 'updating', 'health_checking', 'rolling_back'];
        $ids = [];
        foreach ($states as $state) {
            $ids[] = $this->staleSafeUpdate($state)->id;
        }

        $this->artisan('safe-updates:recover-stuck')->assertSuccessful();

        foreach ($ids as $id) {
            $this->assertSame('failed', SafeUpdate::find($id)->status);
        }
    }

    public function test_fresh_in_progress_safe_update_is_left_alone(): void
    {
        $site = Site::factory()->create();
        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'status' => 'updating',
        ]);

        $this->artisan('safe-updates:recover-stuck')->assertSuccessful();

        $this->assertSame('updating', $safeUpdate->fresh()->status);
    }

    public function test_terminal_safe_updates_are_untouched(): void
    {
        $completed = $this->staleSafeUpdate('completed');
        $failed = $this->staleSafeUpdate('failed');

        $this->artisan('safe-updates:recover-stuck')->assertSuccessful();

        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame('failed', $failed->fresh()->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $safeUpdate = $this->staleSafeUpdate('updating');

        $this->artisan('safe-updates:recover-stuck --dry-run')->assertSuccessful();

        $this->assertSame('updating', $safeUpdate->fresh()->status);
    }
}
