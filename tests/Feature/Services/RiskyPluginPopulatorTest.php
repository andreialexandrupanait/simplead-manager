<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\SafeUpdate;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteRiskyPlugin;
use App\Services\RiskyPluginPopulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SPEC §7.3 — the per-site risk list. The table, the model and the read path in
 * the update decision engine all existed; nothing ever wrote a row, so in
 * production it was empty and the "risky plugin" branch was dead code.
 */
class RiskyPluginPopulatorTest extends TestCase
{
    use RefreshDatabase;

    private function siteWithPlugins(array $slugs): Site
    {
        $site = Site::factory()->create();

        foreach ($slugs as $slug) {
            // `file` is unique per site and the factory picks from a fixed pool,
            // so derive it from the slug to keep several plugins on one site.
            SitePlugin::factory()->for($site)->create([
                'slug' => $slug,
                'name' => $slug,
                'file' => "{$slug}/{$slug}.php",
            ]);
        }

        return $site;
    }

    public function test_each_named_category_is_flagged(): void
    {
        $site = $this->siteWithPlugins([
            'elementor',            // page builder
            'woocommerce',          // commerce
            'woo-stripe-payment',   // payments
            'contact-form-7',       // forms
            'wp-rocket',            // cache
        ]);

        app(RiskyPluginPopulator::class)->populate($site);

        foreach (['elementor', 'woocommerce', 'woo-stripe-payment', 'contact-form-7', 'wp-rocket'] as $slug) {
            $this->assertDatabaseHas('site_risky_plugins', [
                'site_id' => $site->id,
                'slug' => $slug,
                'is_risky' => true,
                'source' => 'auto',
            ]);
        }
    }

    public function test_an_unrelated_plugin_is_left_alone(): void
    {
        $site = $this->siteWithPlugins(['akismet', 'classic-editor']);

        app(RiskyPluginPopulator::class)->populate($site);

        $this->assertDatabaseCount('site_risky_plugins', 0);
    }

    public function test_add_on_slugs_match_their_family(): void
    {
        // The families name their add-ons after themselves, which is exactly why
        // matching is by fragment rather than an exhaustive slug list.
        $site = $this->siteWithPlugins(['elementor-pro', 'wpforms-lite', 'woocommerce-gateway-paypal']);

        app(RiskyPluginPopulator::class)->populate($site);

        $this->assertDatabaseCount('site_risky_plugins', 3);
    }

    public function test_a_manual_decision_is_never_overwritten(): void
    {
        $site = $this->siteWithPlugins(['elementor']);

        // The operator has explicitly decided this one is fine to auto-update.
        SiteRiskyPlugin::create([
            'site_id' => $site->id,
            'slug' => 'elementor',
            'source' => 'manual',
            'is_risky' => false,
            'reason' => 'Reviewed — safe here.',
        ]);

        app(RiskyPluginPopulator::class)->populate($site);

        $this->assertDatabaseHas('site_risky_plugins', [
            'site_id' => $site->id,
            'slug' => 'elementor',
            'source' => 'manual',
            'is_risky' => false,
        ]);
    }

    public function test_running_twice_does_not_duplicate_rows(): void
    {
        $site = $this->siteWithPlugins(['elementor']);
        $populator = app(RiskyPluginPopulator::class);

        $populator->populate($site);
        $populator->populate($site);

        $this->assertDatabaseCount('site_risky_plugins', 1);
    }

    public function test_a_plugin_whose_update_already_failed_here_is_flagged(): void
    {
        $site = $this->siteWithPlugins(['some-niche-plugin']);

        SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'some-niche-plugin',
            'status' => 'failed',
        ]);

        app(RiskyPluginPopulator::class)->populateFromIncidents($site);

        $this->assertDatabaseHas('site_risky_plugins', [
            'site_id' => $site->id,
            'slug' => 'some-niche-plugin',
            'is_risky' => true,
        ]);
    }

    public function test_a_successful_update_does_not_flag_anything(): void
    {
        $site = $this->siteWithPlugins(['some-niche-plugin']);

        SafeUpdate::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'some-niche-plugin',
            'status' => 'completed',
        ]);

        app(RiskyPluginPopulator::class)->populateFromIncidents($site);

        $this->assertDatabaseCount('site_risky_plugins', 0);
    }
}
