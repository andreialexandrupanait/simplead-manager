<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\EvaluateCanaryRollout;
use App\Jobs\QueueSiteSafeUpdatesJob;
use App\Livewire\Sites\SitesList;
use App\Models\SafeUpdate;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\User;
use App\Services\CanaryRolloutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SPEC §7.2 Rule 1 — a fleet update proves itself on two canaries before it
 * touches the rest of the selection.
 *
 * The whole staging path is flag-gated (`updates.smart_rules_enabled`, OFF in
 * production), so the first test here is the one that matters most: with the
 * flag off, behaviour is byte-for-byte what it was before this feature existed.
 */
class CanaryRolloutTest extends TestCase
{
    use RefreshDatabase;

    /** @return \Illuminate\Support\Collection<int, Site> */
    private function eligibleSites(int $count): \Illuminate\Support\Collection
    {
        return Site::factory()->count($count)->create([
            'is_connected' => true,
            'safe_updates_enabled' => true,
        ]);
    }

    private function actingAsManager(): User
    {
        // Admin so `visibleTo` does not scope away sites owned by their factory users.
        $user = User::factory()->create(['role' => \App\Enums\UserRole::Admin]);
        $this->actingAs($user);

        return $user;
    }

    public function test_flag_off_fans_out_to_every_site_immediately(): void
    {
        Queue::fake();
        Config::set('updates.smart_rules_enabled', false);
        $this->actingAsManager();

        $sites = $this->eligibleSites(5);

        Livewire::test(SitesList::class)
            ->set('selectedSites', $sites->pluck('id')->all())
            ->call('bulkUpdate');

        Queue::assertPushed(QueueSiteSafeUpdatesJob::class, 5);
        Queue::assertNotPushed(EvaluateCanaryRollout::class);
    }

    public function test_flag_on_updates_two_canaries_and_defers_the_rest(): void
    {
        Queue::fake();
        Config::set('updates.smart_rules_enabled', true);
        $this->actingAsManager();

        $sites = $this->eligibleSites(5);

        Livewire::test(SitesList::class)
            ->set('selectedSites', $sites->pluck('id')->all())
            ->call('bulkUpdate');

        // Only the two canaries go now; the other three wait for the evaluation.
        Queue::assertPushed(QueueSiteSafeUpdatesJob::class, 2);
        Queue::assertPushed(EvaluateCanaryRollout::class, function (EvaluateCanaryRollout $job): bool {
            return count($job->canarySiteIds) === 2 && count($job->deferredSiteIds) === 3;
        });
    }

    public function test_a_selection_no_larger_than_the_canary_group_is_its_own_canary(): void
    {
        Queue::fake();
        Config::set('updates.smart_rules_enabled', true);
        $this->actingAsManager();

        $sites = $this->eligibleSites(2);

        Livewire::test(SitesList::class)
            ->set('selectedSites', $sites->pluck('id')->all())
            ->call('bulkUpdate');

        Queue::assertPushed(QueueSiteSafeUpdatesJob::class, 2);
        Queue::assertNotPushed(EvaluateCanaryRollout::class);
    }

    public function test_stores_are_ranked_behind_brochure_sites_as_canaries(): void
    {
        Queue::fake();
        Config::set('updates.smart_rules_enabled', true);

        $store = Site::factory()->create(['is_connected' => true, 'safe_updates_enabled' => true]);
        SitePlugin::factory()->create([
            'site_id' => $store->id,
            'slug' => 'woocommerce',
            'name' => 'WooCommerce',
            'file' => 'woocommerce/woocommerce.php',
            'is_active' => true,
        ]);
        $plain = $this->eligibleSites(3);

        $groups = app(CanaryRolloutService::class)->start(
            $plain->push($store)->values()
        );

        $this->assertNotContains($store->id, $groups['canaries'], 'A store must not be the first thing an update lands on.');
        $this->assertContains($store->id, $groups['deferred']);
    }

    public function test_healthy_canaries_release_the_rest_of_the_batch(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('<html><body>fine</body></html>', 200)]);

        $canaries = $this->eligibleSites(2);
        $deferred = $this->eligibleSites(3);

        (new EvaluateCanaryRollout(
            $canaries->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $deferred->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ))->handle(app(CanaryRolloutService::class));

        Queue::assertPushed(QueueSiteSafeUpdatesJob::class, 3);
    }

    public function test_a_failed_update_on_a_canary_holds_the_batch(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('<html><body>fine</body></html>', 200)]);

        $canaries = $this->eligibleSites(2);
        $deferred = $this->eligibleSites(3);

        SafeUpdate::factory()->create([
            'site_id' => $canaries->first()->id,
            'status' => 'failed',
        ]);

        (new EvaluateCanaryRollout(
            $canaries->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $deferred->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ))->handle(app(CanaryRolloutService::class));

        Queue::assertNotPushed(QueueSiteSafeUpdatesJob::class);
    }

    public function test_a_broken_key_url_on_a_canary_holds_the_batch(): void
    {
        Queue::fake();
        // The canary's homepage now returns a PHP fatal — signal 2 of the smoke check.
        Http::fake(['*' => Http::response('<html><body>Fatal error: boom</body></html>', 200)]);

        $canaries = $this->eligibleSites(2);
        $deferred = $this->eligibleSites(3);

        (new EvaluateCanaryRollout(
            $canaries->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $deferred->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ))->handle(app(CanaryRolloutService::class));

        Queue::assertNotPushed(QueueSiteSafeUpdatesJob::class);
    }

    public function test_missing_canaries_hold_the_batch_rather_than_releasing_it(): void
    {
        Queue::fake();
        $deferred = $this->eligibleSites(3);

        (new EvaluateCanaryRollout(
            [999_999],
            $deferred->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ))->handle(app(CanaryRolloutService::class));

        Queue::assertNotPushed(QueueSiteSafeUpdatesJob::class);
    }
}
