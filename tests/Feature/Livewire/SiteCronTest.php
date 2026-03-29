<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\SiteCron;
use App\Models\Site;
use App\Models\User;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteCronTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_site_cron_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteCron::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function cron_data_is_null_before_loading(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SiteCron::class, ['site' => $this->site]);

        $this->assertNull($component->get('cronData'));
    }

    // ─── loadCrons() ──────────────────────────────────────────────────

    #[Test]
    public function load_crons_populates_cron_data_from_api(): void
    {
        $fakeCronData = [
            'crons' => [
                ['hook' => 'wp_scheduled_delete', 'schedule' => 'daily', 'next_run' => 3600, 'disabled' => false, 'args' => [], 'interval' => 86400],
                ['hook' => 'wp_update_themes', 'schedule' => 'twicedaily', 'next_run' => 7200, 'disabled' => false, 'args' => [], 'interval' => 86400],
            ],
            'schedules' => [
                'daily' => ['interval' => 86400, 'display' => 'Once Daily'],
            ],
        ];

        $mockApi = Mockery::mock(\App\Contracts\WordPressApiServiceInterface::class);
        $mockApi->shouldReceive('getCronList')->once()->andReturn($fakeCronData);

        $mockFactory = Mockery::mock(WordPressApiServiceFactory::class);
        $mockFactory->shouldReceive('make')->once()->andReturn($mockApi);

        $this->app->instance(WordPressApiServiceFactory::class, $mockFactory);

        $component = Livewire::actingAs($this->admin)
            ->test(SiteCron::class, ['site' => $this->site])
            ->call('loadCrons');

        $cronData = $component->get('cronData');
        $this->assertNotNull($cronData);
        $this->assertCount(2, $cronData['crons']);
    }

    #[Test]
    public function load_crons_handles_api_exception_gracefully(): void
    {
        $mockApi = Mockery::mock(\App\Contracts\WordPressApiServiceInterface::class);
        $mockApi->shouldReceive('getCronList')->once()->andThrow(new \RuntimeException('Connection refused'));

        $mockFactory = Mockery::mock(WordPressApiServiceFactory::class);
        $mockFactory->shouldReceive('make')->once()->andReturn($mockApi);

        $this->app->instance(WordPressApiServiceFactory::class, $mockFactory);

        $component = Livewire::actingAs($this->admin)
            ->test(SiteCron::class, ['site' => $this->site])
            ->call('loadCrons');

        $this->assertNull($component->get('cronData'));
    }

    // ─── search filter ────────────────────────────────────────────────

    #[Test]
    public function search_filters_cron_list_by_hook_name(): void
    {
        $fakeCronData = [
            'crons' => [
                ['hook' => 'wp_scheduled_delete', 'schedule' => 'daily', 'next_run' => 3600, 'disabled' => false, 'args' => [], 'interval' => 86400],
                ['hook' => 'wp_update_themes', 'schedule' => 'twicedaily', 'next_run' => 7200, 'disabled' => false, 'args' => [], 'interval' => 86400],
                ['hook' => 'custom_newsletter_send', 'schedule' => 'weekly', 'next_run' => 3600, 'disabled' => false, 'args' => [], 'interval' => 86400],
            ],
            'schedules' => [],
        ];

        $mockApi = Mockery::mock(\App\Contracts\WordPressApiServiceInterface::class);
        $mockApi->shouldReceive('getCronList')->once()->andReturn($fakeCronData);

        $mockFactory = Mockery::mock(WordPressApiServiceFactory::class);
        $mockFactory->shouldReceive('make')->once()->andReturn($mockApi);

        $this->app->instance(WordPressApiServiceFactory::class, $mockFactory);

        $component = Livewire::actingAs($this->admin)
            ->test(SiteCron::class, ['site' => $this->site])
            ->call('loadCrons')
            ->set('search', 'wp_update');

        $filtered = $component->instance()->filteredCrons;
        $this->assertCount(1, $filtered);
        $this->assertEquals('wp_update_themes', $filtered[0]['hook']);
    }

    // ─── confirmEnableCron() / enableCron() ───────────────────────────

    #[Test]
    public function confirm_enable_cron_sets_enabling_hook_and_dispatches_event(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SiteCron::class, ['site' => $this->site])
            ->call('confirmEnableCron', 'my_custom_hook');

        $this->assertEquals('my_custom_hook', $component->get('enablingHook'));
        $component->assertDispatched('open-modal-enable-cron');
    }

    #[Test]
    public function enable_cron_calls_api_and_reloads(): void
    {
        $mockApi = Mockery::mock(\App\Contracts\WordPressApiServiceInterface::class);
        $mockApi->shouldReceive('enableCron')
            ->once()
            ->with('my_hook', 'daily');

        // getCronList called by loadCrons() inside enableCron()
        $mockApi->shouldReceive('getCronList')
            ->once()
            ->andReturn(['crons' => [], 'schedules' => []]);

        $mockFactory = Mockery::mock(WordPressApiServiceFactory::class);
        $mockFactory->shouldReceive('make')->andReturn($mockApi);

        $this->app->instance(WordPressApiServiceFactory::class, $mockFactory);

        $component = Livewire::actingAs($this->admin)
            ->test(SiteCron::class, ['site' => $this->site])
            ->set('enablingHook', 'my_hook')
            ->set('enableSchedule', 'daily')
            ->call('enableCron');

        $this->assertNull($component->get('enablingHook'));
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_other_users_site_cron(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SiteCron::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
