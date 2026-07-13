<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Dns\DnsOverview;
use App\Models\DnsChange;
use App\Models\DnsMonitor;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P3-26: (a) DNS "acknowledge" must actually persist an acknowledged timestamp so
 * the change stays acknowledged; (b) Site::dnsDomain() is the single host-
 * derivation helper; (c) DNS overview stats must exclude soft-deleted sites.
 */
class DnsOverviewAcknowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_acknowledge_persists_acknowledged_at(): void
    {
        $site = Site::factory()->create();
        $monitor = DnsMonitor::create(['site_id' => $site->id, 'domain' => $site->dnsDomain(), 'is_active' => true, 'interval_minutes' => 360]);
        $change = DnsChange::create([
            'dns_monitor_id' => $monitor->id,
            'record_type' => 'A',
            'old_value' => ['1.1.1.1'],
            'new_value' => ['2.2.2.2'],
            'detected_at' => now(),
        ]);

        $this->assertNull($change->acknowledged_at);

        Livewire::actingAs($this->admin())
            ->test(DnsOverview::class)
            ->call('acknowledge', $change->id);

        $this->assertNotNull($change->fresh()->acknowledged_at, 'Acknowledge must persist a timestamp.');
    }

    public function test_dns_domain_helper_strips_www_and_derives_host(): void
    {
        $this->assertSame('example.com', Site::factory()->make(['url' => 'https://www.example.com'])->dnsDomain());
        $this->assertSame('example.com', Site::factory()->make(['url' => 'https://example.com/path'])->dnsDomain());
        $this->assertSame('sub.example.com', Site::factory()->make(['url' => 'https://sub.example.com'])->dnsDomain());
    }

    public function test_stats_exclude_soft_deleted_sites(): void
    {
        $live = Site::factory()->create();
        DnsMonitor::create(['site_id' => $live->id, 'domain' => $live->dnsDomain(), 'is_active' => true, 'interval_minutes' => 360, 'current_records' => ['A' => ['1.1.1.1']]]);

        $deleted = Site::factory()->create();
        DnsMonitor::create(['site_id' => $deleted->id, 'domain' => $deleted->dnsDomain(), 'is_active' => true, 'interval_minutes' => 360, 'current_records' => ['A' => ['9.9.9.9']]]);
        $deleted->delete();

        $stats = Livewire::actingAs($this->admin())
            ->test(DnsOverview::class)
            ->instance()
            ->stats();

        $this->assertSame(1, $stats['total'], 'Soft-deleted site monitors must be excluded from stats.');
    }
}
