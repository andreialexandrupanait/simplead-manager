<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CheckDns;
use App\Models\DnsMonitor;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-41 / P2-42: a DNS check that fails (or whose worker is killed on timeout)
 * must record a visible, queryable error state AND advance next_check_at — so a
 * broken monitor is neither invisible nor re-dispatched every minute.
 */
class CheckDnsErrorStateTest extends TestCase
{
    use RefreshDatabase;

    private function throwingJob(DnsMonitor $monitor): CheckDns
    {
        return new class($monitor) extends CheckDns
        {
            protected function fetchDnsRecords(string $domain): array
            {
                throw new \RuntimeException('resolver exploded');
            }
        };
    }

    private function makeMonitor(Site $site): DnsMonitor
    {
        return DnsMonitor::create([
            'site_id' => $site->id,
            'domain' => 'acme.test',
            'is_active' => true,
            'interval_minutes' => 60,
            'next_check_at' => now()->subMinutes(5),
            'current_records' => ['A' => ['1.2.3.4'], 'MX' => [], 'DMARC' => [], 'DKIM' => []],
        ]);
    }

    public function test_failed_check_records_error_state_and_advances_next_check_at(): void
    {
        Queue::fake();

        $monitor = $this->makeMonitor(Site::factory()->create());

        $this->throwingJob($monitor)->handle();

        $fresh = $monitor->fresh();

        // Visible, queryable error state (P2-42).
        $this->assertSame(1, $fresh->consecutive_failures);
        $this->assertNotNull($fresh->last_error);
        $this->assertStringContainsString('resolver exploded', $fresh->last_error);
        $this->assertNotNull($fresh->last_error_at);

        // next_check_at advanced into the future so the dispatcher (which gates
        // on next_check_at <= now()) does not relaunch every minute (P2-41).
        $this->assertTrue($fresh->next_check_at->isFuture());
    }

    public function test_timeout_via_failed_handler_advances_next_check_at(): void
    {
        Queue::fake();

        $monitor = $this->makeMonitor(Site::factory()->create());

        (new CheckDns($monitor))->failed(new \RuntimeException('timeout'));

        $fresh = $monitor->fresh();

        $this->assertSame(1, $fresh->consecutive_failures);
        $this->assertNotNull($fresh->last_error);
        $this->assertTrue($fresh->next_check_at->isFuture());
    }

    public function test_a_subsequent_success_clears_the_error_state(): void
    {
        Queue::fake();

        $monitor = $this->makeMonitor(Site::factory()->create());
        $monitor->update([
            'consecutive_failures' => 3,
            'last_error' => 'previous failure',
            'last_error_at' => now()->subMinute(),
        ]);

        // A stable successful read matching the confirmed records.
        $success = new class($monitor) extends CheckDns
        {
            protected function fetchDnsRecords(string $domain): array
            {
                return [['A' => ['1.2.3.4'], 'MX' => [], 'DMARC' => [], 'DKIM' => []], []];
            }
        };

        $success->handle();

        $fresh = $monitor->fresh();

        $this->assertSame(0, $fresh->consecutive_failures);
        $this->assertNull($fresh->last_error);
        $this->assertNull($fresh->last_error_at);
    }
}
