<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\SiteFirewall;
use App\Models\BlockedRequest;
use App\Models\IpRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class SiteFirewallTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    private User $user;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->site = $this->createSite();
        $this->fakeWordPressApi();
    }

    public function test_component_renders(): void
    {
        Livewire::actingAs($this->user)
            ->test(SiteFirewall::class, ['site' => $this->site])
            ->assertOk()
            ->assertSee('Firewall');
    }

    public function test_can_add_block_rule_with_valid_ip(): void
    {
        Bus::fake();

        Livewire::actingAs($this->user)
            ->test(SiteFirewall::class, ['site' => $this->site])
            ->set('tab', 'block')
            ->set('newIp', '192.168.1.100')
            ->set('newReason', 'Suspicious activity')
            ->call('addRule')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ip_rules', [
            'site_id' => $this->site->id,
            'ip_address' => '192.168.1.100',
            'type' => 'block',
            'reason' => 'Suspicious activity',
        ]);
    }

    public function test_can_add_allow_rule(): void
    {
        Bus::fake();

        Livewire::actingAs($this->user)
            ->test(SiteFirewall::class, ['site' => $this->site])
            ->set('tab', 'allow')
            ->set('newIp', '10.0.0.1')
            ->set('newReason', 'Office IP')
            ->call('addRule')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ip_rules', [
            'site_id' => $this->site->id,
            'ip_address' => '10.0.0.1',
            'type' => 'allow',
            'reason' => 'Office IP',
        ]);
    }

    public function test_validates_ip_format_rejects_invalid_ip(): void
    {
        Livewire::actingAs($this->user)
            ->test(SiteFirewall::class, ['site' => $this->site])
            ->set('newIp', 'not-an-ip')
            ->call('addRule')
            ->assertHasErrors('newIp');
    }

    public function test_can_remove_existing_rule(): void
    {
        Bus::fake();

        $rule = IpRule::factory()->create([
            'site_id' => $this->site->id,
            'ip_address' => '203.0.113.50',
            'type' => 'block',
        ]);

        Livewire::actingAs($this->user)
            ->test(SiteFirewall::class, ['site' => $this->site])
            ->call('removeRule', $rule->id);

        $this->assertDatabaseMissing('ip_rules', [
            'id' => $rule->id,
        ]);
    }

    public function test_displays_existing_block_rules(): void
    {
        $rule = IpRule::factory()->create([
            'site_id' => $this->site->id,
            'ip_address' => '198.51.100.25',
            'type' => 'block',
            'reason' => 'Brute force attempts',
        ]);

        Livewire::actingAs($this->user)
            ->test(SiteFirewall::class, ['site' => $this->site])
            ->assertSee('198.51.100.25')
            ->assertSee('Brute force attempts');
    }

    public function test_validates_required_ip_field(): void
    {
        Livewire::actingAs($this->user)
            ->test(SiteFirewall::class, ['site' => $this->site])
            ->set('newIp', '')
            ->call('addRule')
            ->assertHasErrors('newIp');
    }

    public function test_shows_blocked_requests_count(): void
    {
        $rule = IpRule::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'block',
        ]);

        BlockedRequest::factory()->count(3)->create([
            'site_id' => $this->site->id,
            'ip_rule_id' => $rule->id,
            'blocked_at' => now(),
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(SiteFirewall::class, ['site' => $this->site]);

        // The stats computed property should include the total_blocked count
        $component->assertSee('3');
    }

    public function test_fetch_blocked_dispatches_job(): void
    {
        Bus::fake(\App\Jobs\FetchBlockedRequests::class);

        Livewire::actingAs($this->user)
            ->test(SiteFirewall::class, ['site' => $this->site])
            ->call('fetchBlocked');

        Bus::assertDispatched(\App\Jobs\FetchBlockedRequests::class, function ($job) {
            return $job->site->id === $this->site->id;
        });
    }
}
