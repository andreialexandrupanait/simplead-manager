<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Backup;
use App\Models\Site;
use App\Services\Backup\ManifestService;
use App\Services\Backup\SandboxRestoreService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * C-08: the post-restore health-check evaluation — homepage 200, login reachable,
 * connector loopback OK, DB row counts coherent with the manifest. A single
 * failing check must fail the whole proof.
 */
class SandboxRestoreHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function service(array $manifest = []): SandboxRestoreService
    {
        $fake = $this->fakeApi(); // binds the factory + gives a scriptable connector
        $fake->script('runDiagnostic', [
            'loopback' => ['status' => 200],
            'database' => ['total_rows' => 100],
        ]);

        $manifestService = Mockery::mock(ManifestService::class);
        $manifestService->shouldReceive('retrieve')->andReturn($manifest);

        return new SandboxRestoreService(app(WordPressApiServiceFactory::class), $manifestService);
    }

    private function sandbox(): Site
    {
        return Site::factory()->create(['is_sandbox' => true, 'url' => 'http://sandbox-wp']);
    }

    public function test_all_checks_green_passes(): void
    {
        Http::fake([
            'sandbox-wp/wp-login.php' => Http::response('login', 200),
            'sandbox-wp/*' => Http::response('home', 200),
        ]);

        $result = $this->service(['database' => ['total_rows' => 100]])
            ->runHealthChecks($this->sandbox(), Backup::factory()->create());

        $this->assertTrue($result['passed']);
        $this->assertSame(
            ['homepage_200' => true, 'login_reachable' => true, 'loopback_ok' => true, 'db_coherent' => true],
            $result['checks'],
        );
    }

    public function test_a_500_homepage_fails_the_proof(): void
    {
        Http::fake([
            'sandbox-wp/wp-login.php' => Http::response('login', 200),
            'sandbox-wp/*' => Http::response('boom', 500),
        ]);

        $result = $this->service()->runHealthChecks($this->sandbox(), Backup::factory()->create());

        $this->assertFalse($result['passed']);
        $this->assertFalse($result['checks']['homepage_200']);
    }

    public function test_db_far_below_the_manifest_baseline_fails(): void
    {
        Http::fake([
            'sandbox-wp/wp-login.php' => Http::response('login', 200),
            'sandbox-wp/*' => Http::response('home', 200),
        ]);

        // Manifest says 1000 rows but the restored DB reports only 100 (10%) —
        // the dump clearly did not import.
        $result = $this->service(['database' => ['total_rows' => 1000]])
            ->runHealthChecks($this->sandbox(), Backup::factory()->create());

        $this->assertFalse($result['passed']);
        $this->assertFalse($result['checks']['db_coherent']);
    }
}
