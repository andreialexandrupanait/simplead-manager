<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Jobs\CheckBrokenLinks;
use App\Jobs\CheckWooHealth;
use App\Livewire\Sites\Detail\SiteChecks;
use App\Models\LinkCheckResult;
use App\Models\LinkCheckRun;
use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Models\User;
use App\Models\WooCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SPEC §3.2's "Verificări" group. The engines behind all four tabs already ran and
 * wrote their results to the database; nothing read them back. There was no screen,
 * no report section, and for the form test not even a caller.
 */
class SiteChecksTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        // Assets are built inside the Docker image, so a test checkout has no Vite
        // manifest — a full-page GET would 500 on that alone.
        $this->withoutVite();
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->site = Site::factory()->create(['is_connected' => true]);
    }

    public static function tabRoutes(): array
    {
        return [
            'forms' => ['sites.checks.forms', 'forms'],
            'woocommerce' => ['sites.checks.woo', 'woo'],
            'broken links' => ['sites.checks.links', 'links'],
            'php errors' => ['sites.checks.errors', 'errors'],
        ];
    }

    /** @dataProvider tabRoutes */
    public function test_each_route_lands_on_its_own_tab(string $routeName, string $expectedTab): void
    {
        $this->get(route($routeName, $this->site))->assertOk();

        Livewire::withQueryParams([])
            ->test(SiteChecks::class, ['site' => $this->site])
            ->assertOk();

        // The route drives the tab, so no click is needed to get there.
        $this->get(route($routeName, $this->site))->assertSee($expectedTab, escape: false);
    }

    public function test_woo_results_are_shown_rather_than_only_stored(): void
    {
        WooCheck::create([
            'site_id' => $this->site->id,
            'applicable' => true,
            'checkout_status' => 200,
            'gateways_ok' => true,
            'pending_orders_count' => 4,
            'pending_over_threshold' => false,
            'discount_cron_scheduled' => true,
            'checked_at' => now(),
        ]);

        Livewire::test(SiteChecks::class, ['site' => $this->site])
            ->set('tab', 'woo')
            ->assertSee('HTTP 200')
            ->assertSee('4');
    }

    /**
     * SPEC §5.7: "Raportare pe diferență, nu pe stare." The counts were computed
     * and persisted on the run; the sentence they exist for was never rendered.
     */
    public function test_broken_links_are_reported_as_the_change_since_last_scan(): void
    {
        $run = LinkCheckRun::create([
            'site_id' => $this->site->id,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now(),
            'total_urls' => 180,
            'broken_count' => 5,
            'chains_count' => 2,
            'new_broken_count' => 3,
            'resolved_count' => 2,
        ]);

        LinkCheckResult::create([
            'run_id' => $run->id,
            'url' => 'https://example.test/gone',
            'source_page' => 'https://example.test/about',
            'status_code' => 404,
            'is_internal' => true,
            'is_broken' => true,
        ]);

        Livewire::test(SiteChecks::class, ['site' => $this->site])
            ->set('tab', 'links')
            ->assertSee('3 newly broken links')
            ->assertSee('2 resolved')
            ->assertSee('https://example.test/gone');
    }

    public function test_php_errors_are_attributed_to_the_plugin_that_caused_them(): void
    {
        PhpErrorLog::create([
            'site_id' => $this->site->id,
            'level' => 'warning',
            'message' => 'Undefined array key "foo"',
            'file' => '/wp-content/plugins/jet-woo-builder/render.php',
            'line' => 88,
            'message_hash' => md5('one'),
            'count' => 847,
            'source_type' => 'plugin',
            'source_slug' => 'jet-woo-builder',
            'first_seen_at' => now()->subDays(3),
            'last_seen_at' => now(),
        ]);

        Livewire::test(SiteChecks::class, ['site' => $this->site])
            ->set('tab', 'errors')
            ->assertSee('jet-woo-builder')
            ->assertSee('847');
    }

    /**
     * Both check jobs sweep the whole fleet by default. A "check now" button on one
     * site must target that site, or one operator click fires 27 connector calls.
     */
    public function test_run_now_targets_only_this_site(): void
    {
        Livewire::test(SiteChecks::class, ['site' => $this->site])
            ->call('runWooCheck')
            ->call('runLinkCheck');

        Queue::assertPushed(CheckWooHealth::class, fn ($job) => $job->siteId === $this->site->id);
        Queue::assertPushed(CheckBrokenLinks::class, fn ($job) => $job->siteId === $this->site->id);
    }

    public function test_a_viewer_cannot_trigger_a_check(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        // The viewer owns the site, so read access is granted and the block under
        // test is the write guard rather than plain site visibility.
        $site = Site::factory()->create(['user_id' => $viewer->id, 'is_connected' => true]);

        $this->actingAs($viewer);

        Livewire::test(SiteChecks::class, ['site' => $site])
            ->call('runWooCheck')
            ->assertForbidden();
    }
}
