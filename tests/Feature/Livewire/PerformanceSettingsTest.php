<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SitePerformance;
use App\Models\PerformanceMonitor;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P0-19: the 'Manual only' frequency offered in the UI was rejected by
 * validation (in:daily,weekly,monthly), so it could never be saved.
 */
class PerformanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_only_frequency_can_be_saved(): void
    {
        Queue::fake();

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id]);
        $monitor = PerformanceMonitor::create(['site_id' => $site->id]);

        Livewire::actingAs($manager)
            ->test(SitePerformance::class, ['site' => $site])
            ->set('settingsFrequency', 'manual')
            ->set('settingsTestTime', '04:00')
            ->set('settingsThreshold', 10)
            ->call('updateSettings')
            ->assertHasNoErrors();

        $this->assertSame('manual', $monitor->fresh()->frequency);
    }

    public function test_unknown_frequency_is_rejected(): void
    {
        Queue::fake();

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id]);
        PerformanceMonitor::create(['site_id' => $site->id]);

        Livewire::actingAs($manager)
            ->test(SitePerformance::class, ['site' => $site])
            ->set('settingsFrequency', 'monthly')
            ->set('settingsTestTime', '04:00')
            ->set('settingsThreshold', 10)
            ->call('updateSettings')
            ->assertHasErrors('settingsFrequency');
    }
}
