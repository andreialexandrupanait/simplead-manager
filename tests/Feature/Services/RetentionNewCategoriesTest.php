<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\RetentionCleanup;
use App\Models\Site;
use App\Models\User;
use App\Services\RetentionPolicyService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * P2-43: previously-unpruned growing tables (dns_changes, php_error_logs,
 * in_app_notifications, incident_responses) must be registered for retention.
 * They are gated behind config('backups.retention_dry_run'): while it is on the
 * job LOGS the count it would prune without deleting; once flipped off it prunes.
 */
class RetentionNewCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy(); // JobTracker writes progress to Redis
        Queue::fake(); // keep Site-creation side-effect jobs off the sync queue
    }

    private function runCleanup(): void
    {
        (new RetentionCleanup)->handle(
            app(RetentionPolicyService::class),
            app(SettingsService::class),
        );
    }

    private function lastResult(): array
    {
        return app(SettingsService::class)->get('retention_last_run_result') ?? [];
    }

    public function test_new_categories_are_registered_with_expected_tables(): void
    {
        $categories = RetentionPolicyService::CATEGORIES;

        $this->assertArrayHasKey('dns_history', $categories);
        $this->assertArrayHasKey('php_error_logs', $categories);
        $this->assertArrayHasKey('in_app_notifications', $categories);
        $this->assertArrayHasKey('incident_responses', $categories);

        $this->assertSame('dns_changes', $categories['dns_history']['tables'][0]['table']);
        $this->assertSame('detected_at', $categories['dns_history']['tables'][0]['column']);
        $this->assertSame('last_seen_at', $categories['php_error_logs']['tables'][0]['column']);

        // incident_responses only prunes terminal incidents.
        $this->assertSame(
            ['status', 'in', ['resolved', 'failed', 'escalated']],
            $categories['incident_responses']['tables'][0]['condition'],
        );

        // All four are dry-run gated.
        foreach (['dns_history', 'php_error_logs', 'in_app_notifications', 'incident_responses'] as $key) {
            $this->assertTrue($categories[$key]['dry_run'] ?? false, "{$key} must be dry-run gated");
        }
    }

    public function test_dry_run_logs_count_without_deleting(): void
    {
        Config::set('backups.retention_dry_run', true);

        $user = User::factory()->create();

        // Old row (beyond the 60-day window) + fresh row.
        DB::table('in_app_notifications')->insert([
            'user_id' => $user->id,
            'type' => 'info',
            'title' => 'old',
            'created_at' => now()->subDays(400),
            'updated_at' => now()->subDays(400),
        ]);
        DB::table('in_app_notifications')->insert([
            'user_id' => $user->id,
            'type' => 'info',
            'title' => 'fresh',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runCleanup();

        // DRY-RUN: nothing deleted, both rows survive.
        $this->assertSame(2, DB::table('in_app_notifications')->count());

        $result = $this->lastResult();
        $this->assertTrue($result['categories']['in_app_notifications']['dry_run']);
        $this->assertSame(1, $result['categories']['in_app_notifications']['would_delete']);
        $this->assertSame(0, $result['categories']['in_app_notifications']['deleted']);
    }

    public function test_prunes_old_rows_once_dry_run_flag_is_off(): void
    {
        Config::set('backups.retention_dry_run', false);

        $site = Site::factory()->create();

        // php_error_logs prunes by last_seen_at.
        DB::table('php_error_logs')->insert([
            'site_id' => $site->id,
            'level' => 'error',
            'message' => 'old error',
            'message_hash' => str_repeat('a', 32),
            'first_seen_at' => now()->subDays(400),
            'last_seen_at' => now()->subDays(400),
            'created_at' => now()->subDays(400),
            'updated_at' => now()->subDays(400),
        ]);
        DB::table('php_error_logs')->insert([
            'site_id' => $site->id,
            'level' => 'error',
            'message' => 'recent error',
            'message_hash' => str_repeat('b', 32),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runCleanup();

        // Only the stale row is pruned.
        $this->assertSame(1, DB::table('php_error_logs')->count());
        $this->assertSame('recent error', DB::table('php_error_logs')->value('message'));

        $result = $this->lastResult();
        $this->assertSame(1, $result['categories']['php_error_logs']['deleted']);
    }
}
