<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Restore;

use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Restore\RestoreHealthCheck;
use App\Backup\V2\Restore\RestorePlan;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RestoreHealthCheck — the REAL post-restore probe (site HTTP + DB tables/rows) that
 * replaces the previous trivially-passing `null`. A failure returns false, which drives
 * the RestoreRunner's guaranteed rollback.
 */
class RestoreHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::factory()->create([
            'url' => 'https://restored.example',
            'api_key' => 'k',
            'api_secret' => 's',
        ]);
    }

    private function plan(bool $includeDatabase = true): RestorePlan
    {
        return new RestorePlan(
            fileChunks: [],
            keepPaths: [],
            dbChunks: [],
            tombstones: [],
            dbTables: [],
            mirrorRoots: [],
            includeDatabase: $includeDatabase,
            includeFiles: true,
        );
    }

    private function check(Site $site): RestoreHealthCheck
    {
        return new RestoreHealthCheck($site, app(HttpFactory::class));
    }

    public function test_healthy_site_with_populated_tables_passes(): void
    {
        Http::fake([
            '*database-health' => Http::response([
                'table_prefix' => 'wp_',
                'tables' => [
                    ['name' => 'wp_posts', 'rows' => 42],
                    ['name' => 'wp_options', 'rows' => 300],
                    ['name' => 'wp_users', 'rows' => 5],
                ],
            ], 200),
            '*' => Http::response('<html>OK</html>', 200),
        ]);

        $this->assertTrue(($this->check($this->site()))(new RestoreSession, $this->plan()));
    }

    public function test_site_returning_http_500_fails(): void
    {
        Http::fake([
            '*' => Http::response('fatal error', 500),
        ]);

        $this->assertFalse(($this->check($this->site()))(new RestoreSession, $this->plan()));
    }

    public function test_empty_core_tables_fail(): void
    {
        Http::fake([
            '*database-health' => Http::response([
                'table_prefix' => 'wp_',
                'tables' => [
                    ['name' => 'wp_posts', 'rows' => 0],
                    ['name' => 'wp_options', 'rows' => 0],
                    ['name' => 'wp_users', 'rows' => 0],
                ],
            ], 200),
            '*' => Http::response('<html>OK</html>', 200),
        ]);

        $this->assertFalse(($this->check($this->site()))(new RestoreSession, $this->plan()));
    }

    public function test_missing_core_table_fails(): void
    {
        Http::fake([
            '*database-health' => Http::response([
                'table_prefix' => 'wp_',
                'tables' => [
                    // wp_users is absent — an incomplete DB restore.
                    ['name' => 'wp_posts', 'rows' => 42],
                    ['name' => 'wp_options', 'rows' => 300],
                ],
            ], 200),
            '*' => Http::response('<html>OK</html>', 200),
        ]);

        $this->assertFalse(($this->check($this->site()))(new RestoreSession, $this->plan()));
    }

    public function test_files_only_restore_skips_db_probe_and_passes_on_reachable_site(): void
    {
        // Only the homepage responds; no database-health stub — proves the DB probe is skipped.
        Http::fake([
            '*' => Http::response('<html>OK</html>', 200),
        ]);

        $this->assertTrue(($this->check($this->site()))(new RestoreSession, $this->plan(includeDatabase: false)));
    }
}
