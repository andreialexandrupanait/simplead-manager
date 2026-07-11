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
 * P0-27 (E-30): a transient resolver failure (dns_get_record() === false) was
 * stored as an empty record set, producing a false "records deleted" alert and
 * false data in client reports. The fix carries the previous value forward on a
 * lookup failure and requires two consecutive observations before a change is
 * committed.
 */
class CheckDnsTransientFailureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A CheckDns whose DNS fetch is stubbed so the debounce/carry-forward logic
     * can be exercised without touching a real resolver.
     *
     * @param  list<array{0: array<string, mixed>, 1: list<string>}>  $results
     */
    private function fakeJob(DnsMonitor $monitor, array $results): CheckDns
    {
        return new class($monitor, $results) extends CheckDns
        {
            /** @var list<array{0: array<string, mixed>, 1: list<string>}> */
            public array $results;

            public function __construct(DnsMonitor $monitor, array $results)
            {
                parent::__construct($monitor);
                $this->results = $results;
            }

            protected function fetchDnsRecords(string $domain): array
            {
                return array_shift($this->results) ?? [[], []];
            }
        };
    }

    public function test_transient_failure_carries_forward_and_records_no_change(): void
    {
        Queue::fake();

        $site = Site::factory()->create();
        $monitor = DnsMonitor::create([
            'site_id' => $site->id,
            'domain' => 'acme.test',
            'is_active' => true,
            'interval_minutes' => 60,
            'current_records' => ['A' => ['1.2.3.4'], 'MX' => [], 'DMARC' => [], 'DKIM' => []],
        ]);

        // A resolver failure on the A lookup: empty placeholder + 'A' flagged failed.
        $job = $this->fakeJob($monitor, [
            [['A' => [], 'MX' => [], 'DMARC' => [], 'DKIM' => []], ['A']],
        ]);

        $job->handle();

        $fresh = $monitor->fresh();

        // No false "records deleted" alert.
        $this->assertDatabaseCount('dns_changes', 0);
        $this->assertFalse($fresh->has_changes);

        // Previous A value carried forward — reports never show a false "Missing".
        $this->assertSame(['1.2.3.4'], $fresh->current_records['A']);
    }

    public function test_change_requires_two_consecutive_observations(): void
    {
        Queue::fake();

        $site = Site::factory()->create();
        $monitor = DnsMonitor::create([
            'site_id' => $site->id,
            'domain' => 'acme.test',
            'is_active' => true,
            'interval_minutes' => 60,
            'current_records' => ['A' => ['1.2.3.4'], 'MX' => [], 'DMARC' => [], 'DKIM' => []],
        ]);

        $changed = [['A' => ['9.9.9.9'], 'MX' => [], 'DMARC' => [], 'DKIM' => []], []];

        // First observation of the new value — held as pending, no alert yet.
        $this->fakeJob($monitor, [$changed])->handle();

        $this->assertDatabaseCount('dns_changes', 0);
        $fresh = $monitor->fresh();
        $this->assertFalse($fresh->has_changes);
        $this->assertSame(['1.2.3.4'], $fresh->current_records['A']);
        $this->assertSame(['9.9.9.9'], $fresh->pending_records['A']);

        // Second consecutive identical observation — the change is committed.
        $this->fakeJob($monitor->fresh(), [$changed])->handle();

        $this->assertDatabaseCount('dns_changes', 1);
        $this->assertDatabaseHas('dns_changes', [
            'dns_monitor_id' => $monitor->id,
            'record_type' => 'A',
        ]);

        $fresh = $monitor->fresh();
        $this->assertTrue($fresh->has_changes);
        $this->assertSame(['9.9.9.9'], $fresh->current_records['A']);
        $this->assertNull($fresh->pending_records);
    }

    public function test_single_blip_between_stable_reads_never_commits_a_change(): void
    {
        Queue::fake();

        $site = Site::factory()->create();
        $monitor = DnsMonitor::create([
            'site_id' => $site->id,
            'domain' => 'acme.test',
            'is_active' => true,
            'interval_minutes' => 60,
            'current_records' => ['A' => ['1.2.3.4'], 'MX' => [], 'DMARC' => [], 'DKIM' => []],
        ]);

        // Blip returns a spuriously different value once, then recovers.
        $this->fakeJob($monitor, [
            [['A' => ['0.0.0.0'], 'MX' => [], 'DMARC' => [], 'DKIM' => []], []],
        ])->handle();

        $this->fakeJob($monitor->fresh(), [
            [['A' => ['1.2.3.4'], 'MX' => [], 'DMARC' => [], 'DKIM' => []], []],
        ])->handle();

        $this->assertDatabaseCount('dns_changes', 0);
        $this->assertNull($monitor->fresh()->pending_records);
    }
}
