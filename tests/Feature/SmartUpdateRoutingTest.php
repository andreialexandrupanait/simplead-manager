<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\UpdateDecision;
use App\Enums\UpdateRoute;
use App\Enums\UserRole;
use App\Jobs\RunSafeUpdate;
use App\Livewire\Sites\Detail\SitePlugins;
use App\Livewire\Updates\UpdatesOverview;
use App\Models\SafeUpdate;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\User;
use App\Services\UpdateDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faza 6 — proves the smart update-decision engine is wired ADDITIVELY and
 * strictly under the `updates.smart_rules_enabled` flag (default OFF). The
 * headline guarantee (first test) is that with the flag OFF the safe-update
 * dispatch does EXACTLY what it did before the engine existed.
 */
class SmartUpdateRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
    }

    private function siteWithUpdatablePlugin(string $from = '5.0.0', string $to = '5.0.1'): array
    {
        $site = Site::factory()->create([
            'user_id' => $this->manager->id,
            'is_connected' => true,
            'safe_updates_enabled' => true,
        ]);

        $plugin = SitePlugin::factory()->create([
            'site_id' => $site->id,
            'slug' => 'akismet',
            'file' => 'akismet/akismet.php',
            'name' => 'Akismet',
            'has_update' => true,
            'version' => $from,
            'update_version' => $to,
        ]);

        return [$site, $plugin];
    }

    /**
     * CRITICAL SAFETY TEST: with the smart-rules flag OFF (the default),
     * queueSafeUpdate must create the SafeUpdate and dispatch RunSafeUpdate
     * exactly as before — no approval gate, no risk score, no engine call.
     */
    public function test_flag_off_queue_safe_update_behaves_exactly_as_before(): void
    {
        config(['updates.smart_rules_enabled' => false]);
        Queue::fake();

        // The engine must not even be consulted when the flag is off.
        $this->mock(UpdateDecisionService::class, function ($mock) {
            $mock->shouldNotReceive('decide');
        });

        [$site, $plugin] = $this->siteWithUpdatablePlugin();

        Livewire::actingAs($this->manager)
            ->test(SitePlugins::class, ['site' => $site])
            ->call('updatePlugin', $plugin->id);

        // Dispatched immediately, exactly as before.
        Queue::assertPushed(RunSafeUpdate::class);

        $this->assertDatabaseHas('safe_updates', [
            'site_id' => $site->id,
            'slug' => 'akismet',
            'target' => 'akismet/akismet.php',
            'status' => 'pending',
            'approval_required' => false,
        ]);
    }

    /**
     * Flag ON + AwaitApproval route → the update is HELD: no dispatch,
     * approval_required = true.
     */
    public function test_flag_on_await_approval_holds_update_without_dispatch(): void
    {
        config(['updates.smart_rules_enabled' => true]);
        Queue::fake();

        $this->mock(UpdateDecisionService::class, function ($mock) {
            $mock->shouldReceive('decide')->once()->andReturn(
                new UpdateDecision(UpdateRoute::AwaitApproval, ['Major version bump.']),
            );
        });

        [$site, $plugin] = $this->siteWithUpdatablePlugin('5.0.0', '6.0.0');

        Livewire::actingAs($this->manager)
            ->test(SitePlugins::class, ['site' => $site])
            ->call('updatePlugin', $plugin->id);

        Queue::assertNotPushed(RunSafeUpdate::class);

        $safeUpdate = SafeUpdate::where('site_id', $site->id)->firstOrFail();
        $this->assertTrue($safeUpdate->approval_required);
        $this->assertSame('pending', $safeUpdate->status);
    }

    /**
     * Flag ON + AutoMinor route → dispatched as normal, no approval gate.
     */
    public function test_flag_on_auto_minor_dispatches(): void
    {
        config(['updates.smart_rules_enabled' => true]);
        Queue::fake();

        $this->mock(UpdateDecisionService::class, function ($mock) {
            $mock->shouldReceive('decide')->once()->andReturn(
                new UpdateDecision(UpdateRoute::AutoMinor, ['Minor/patch update.']),
            );
        });

        [$site, $plugin] = $this->siteWithUpdatablePlugin('5.0.0', '5.0.1');

        Livewire::actingAs($this->manager)
            ->test(SitePlugins::class, ['site' => $site])
            ->call('updatePlugin', $plugin->id);

        Queue::assertPushed(RunSafeUpdate::class);

        $safeUpdate = SafeUpdate::where('site_id', $site->id)->firstOrFail();
        $this->assertFalse($safeUpdate->approval_required);
    }

    /**
     * Flag ON + CriticalBypass route → dispatched immediately (security wins),
     * no approval gate.
     */
    public function test_flag_on_critical_bypass_dispatches(): void
    {
        config(['updates.smart_rules_enabled' => true]);
        Queue::fake();

        $this->mock(UpdateDecisionService::class, function ($mock) {
            $mock->shouldReceive('decide')->once()->andReturn(
                new UpdateDecision(UpdateRoute::CriticalBypass, ['Active critical vulnerability.']),
            );
        });

        [$site, $plugin] = $this->siteWithUpdatablePlugin();

        Livewire::actingAs($this->manager)
            ->test(SitePlugins::class, ['site' => $site])
            ->call('updatePlugin', $plugin->id);

        Queue::assertPushed(RunSafeUpdate::class);
        $this->assertFalse(SafeUpdate::where('site_id', $site->id)->firstOrFail()->approval_required);
    }

    /**
     * Flag ON + a real major version bump through the REAL engine (no mock) →
     * held for approval. Proves the end-to-end wiring, not just a mocked route.
     */
    public function test_flag_on_real_engine_holds_major_bump(): void
    {
        config(['updates.smart_rules_enabled' => true]);
        Queue::fake();

        [$site, $plugin] = $this->siteWithUpdatablePlugin('5.0.0', '6.0.0');

        Livewire::actingAs($this->manager)
            ->test(SitePlugins::class, ['site' => $site])
            ->call('updatePlugin', $plugin->id);

        Queue::assertNotPushed(RunSafeUpdate::class);
        $this->assertTrue(SafeUpdate::where('site_id', $site->id)->firstOrFail()->approval_required);
    }

    /**
     * If the engine throws, the FAIL-SAFE holds the update for approval rather
     * than auto-applying it or letting the exception break the update flow.
     */
    public function test_engine_exception_is_fail_safe_hold(): void
    {
        config(['updates.smart_rules_enabled' => true]);
        Queue::fake();

        $this->mock(UpdateDecisionService::class, function ($mock) {
            $mock->shouldReceive('decide')->once()->andThrow(new \RuntimeException('boom'));
        });

        [$site, $plugin] = $this->siteWithUpdatablePlugin();

        Livewire::actingAs($this->manager)
            ->test(SitePlugins::class, ['site' => $site])
            ->call('updatePlugin', $plugin->id);

        Queue::assertNotPushed(RunSafeUpdate::class);
        $this->assertTrue(SafeUpdate::where('site_id', $site->id)->firstOrFail()->approval_required);
    }

    /**
     * approveUpdate clears the gate and dispatches the held update through the
     * normal pipeline.
     */
    public function test_approve_update_dispatches_held_safe_update(): void
    {
        Queue::fake();

        $site = Site::factory()->create([
            'user_id' => $this->manager->id,
            'is_connected' => true,
            'safe_updates_enabled' => true,
        ]);

        $safeUpdate = SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'status' => 'pending',
            'approval_required' => true,
        ]);

        Livewire::actingAs($this->manager)
            ->test(UpdatesOverview::class)
            ->call('approveUpdate', $safeUpdate->id);

        Queue::assertPushed(RunSafeUpdate::class);
        $this->assertFalse($safeUpdate->refresh()->approval_required);
    }
}
