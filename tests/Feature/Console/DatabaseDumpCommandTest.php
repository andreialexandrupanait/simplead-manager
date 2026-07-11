<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class DatabaseDumpCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dumpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dumpDir = storage_path('app/db-dumps');
    }

    protected function tearDown(): void
    {
        // Remove any artifacts this test may have produced.
        foreach (glob($this->dumpDir.'/db_dump_*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_failed_pg_dump_reports_failure_and_fires_critical_notification(): void
    {
        Bus::fake();

        // A default, active channel subscribed to everything (null subscriptions).
        NotificationChannel::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'event_subscriptions' => null,
        ]);

        // Point pg_dump at an unreachable target so the dump genuinely fails.
        config([
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 1,
            'database.connections.pgsql.database' => 'does_not_exist',
            'database.connections.pgsql.username' => 'nobody',
            'database.connections.pgsql.password' => 'nope',
        ]);

        $this->artisan('db:dump')->assertFailed();

        // No artifact must survive a failed dump — the false "success" is gone.
        $this->assertSame(
            [],
            glob($this->dumpDir.'/db_dump_*.sql.gz') ?: [],
            'A failed dump must not leave a .sql.gz artifact behind.'
        );

        // A critical platform notification is fired so the failure is not silent.
        Bus::assertDispatchedSync(SendNotificationJob::class, function (SendNotificationJob $job) {
            return $job->event === 'db_dump_failed' && $job->severity === 'critical';
        });
    }
}
