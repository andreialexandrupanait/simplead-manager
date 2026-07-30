<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModuleAccessLog;
use App\Services\ModuleUsageTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Faza 7 — usage instrumentation for the modules under observation.
 * Verifies the tracker records access, debounces repeat hits, and never
 * throws (instrumentation must not break a page).
 */
class ModuleUsageTrackerTest extends TestCase
{
    use RefreshDatabase;

    private function tracker(): ModuleUsageTracker
    {
        return app(ModuleUsageTracker::class);
    }

    public function test_record_inserts_a_row(): void
    {
        $this->tracker()->record('redirects', null, 7);

        $this->assertSame(1, ModuleAccessLog::where('module', 'redirects')->count());

        $row = ModuleAccessLog::first();
        $this->assertSame('redirects', $row->module);
        $this->assertSame(7, $row->user_id);
        $this->assertNotNull($row->accessed_at);
    }

    public function test_debounce_collapses_rapid_same_key_hits_to_one_row(): void
    {
        Cache::flush();

        $this->tracker()->record('database_cleanup', null, 3);
        $this->tracker()->record('database_cleanup', null, 3);

        $this->assertSame(1, ModuleAccessLog::where('module', 'database_cleanup')->count());
    }

    public function test_debounce_is_per_key_not_global(): void
    {
        Cache::flush();

        $this->tracker()->record('tweaks:performance', null, 3);
        $this->tracker()->record('tweaks:performance', null, 9); // different user
        $this->tracker()->record('tweaks:admin_ux', null, 3);     // different module

        $this->assertSame(3, ModuleAccessLog::count());
    }

    public function test_record_fails_silently_when_table_is_missing(): void
    {
        Cache::flush();
        DB::statement('DROP TABLE IF EXISTS module_access_logs');

        // Must not throw even though the underlying insert will error.
        $this->tracker()->record('plugin_conflict', 1, null);

        $this->assertTrue(true);
    }
}
