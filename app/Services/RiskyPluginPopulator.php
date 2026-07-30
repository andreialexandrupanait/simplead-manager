<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SafeUpdate;
use App\Models\Site;
use App\Models\SiteRiskyPlugin;
use Illuminate\Support\Facades\Log;

/**
 * SPEC §7.3 — the per-site risk list, "populată automat la conectare, din
 * categoria pluginului (page buildere, comerț, plăți, formulare, cache) și din
 * istoricul de incidente. Suprascrisă manual acolo unde e nevoie."
 *
 * The table and the read path existed since Faza 6, but nothing ever wrote to
 * it — in production it held zero rows, so the "risky plugin" branch of the
 * update decision engine could never fire.
 *
 * Deliberately NOT AI-driven: category membership is a fact about what a plugin
 * does, not a judgement call, and it must be available the moment a site
 * connects. {@see PluginRiskAssessmentService} still scores the individual
 * update; this decides which plugins are never worth auto-applying at all.
 *
 * Manual entries are sacred: a row with source = 'manual' is never overwritten,
 * in either direction, so an operator's explicit "this one is fine" survives
 * every later sync.
 */
class RiskyPluginPopulator
{
    /**
     * Category => slug fragments. Matched as substrings against the plugin slug
     * (lowercased), which is how these families actually name themselves —
     * "elementor-pro", "woocommerce-gateway-stripe", "wpforms-lite" all need to
     * match without an exhaustive list of every add-on.
     *
     * @var array<string, list<string>>
     */
    private const CATEGORIES = [
        'page builder' => [
            'elementor', 'divi', 'js_composer', 'wpbakery', 'beaver-builder', 'bb-plugin',
            'brizy', 'oxygen', 'fusion-builder', 'siteorigin-panels', 'thrive-', 'bricks',
        ],
        'commerce' => [
            'woocommerce', 'easy-digital-downloads', 'wp-ecommerce', 'jigoshop',
        ],
        'payments' => [
            'stripe', 'paypal', 'mollie', 'netopia', 'payu', 'razorpay', 'klarna',
            'authorize-net', 'square', 'euplatesc',
        ],
        'forms' => [
            'contact-form-7', 'wpforms', 'gravityforms', 'ninja-forms', 'fluentform',
            'formidable', 'forminator', 'caldera-forms',
        ],
        'cache' => [
            'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'litespeed-cache',
            'wp-fastest-cache', 'autoptimize', 'cache-enabler', 'swift-performance',
            'nitropack', 'wp-optimize',
        ],
    ];

    /**
     * Flag the site's risky-by-category plugins. Idempotent — safe to run on
     * every sync, not just the first.
     *
     * @return int rows written or refreshed
     */
    public function populate(Site $site): int
    {
        $written = 0;

        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\SitePlugin> $plugins */
        $plugins = $site->sitePlugins()->get(['slug', 'name']);

        foreach ($plugins as $plugin) {
            $slug = strtolower((string) $plugin->slug);

            if ($slug === '') {
                continue;
            }

            $category = $this->categoryFor($slug);

            if ($category === null) {
                continue;
            }

            if ($this->flag($site, $slug, "Category: {$category} — a bad update here breaks the site visibly.")) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * The other half of §7.3: incident history. A plugin whose safe update has
     * already failed on THIS site is not a candidate for auto-applying the next
     * one, whatever its category.
     *
     * @return int rows written or refreshed
     */
    public function populateFromIncidents(Site $site): int
    {
        $failedSlugs = SafeUpdate::query()
            ->where('site_id', $site->id)
            ->where('status', 'failed')
            ->where('type', 'plugin')
            ->pluck('slug')
            ->filter()
            ->unique();

        $written = 0;

        foreach ($failedSlugs as $slug) {
            if ($this->flag($site, strtolower((string) $slug), 'A previous update of this plugin failed on this site.')) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Write an automatic entry, leaving any manual decision untouched.
     */
    private function flag(Site $site, string $slug, string $reason): bool
    {
        try {
            $existing = SiteRiskyPlugin::query()->forSitePlugin($site->id, $slug)->first();

            if ($existing !== null && $existing->source === 'manual') {
                return false;
            }

            SiteRiskyPlugin::updateOrCreate(
                ['site_id' => $site->id, 'slug' => $slug],
                ['source' => 'auto', 'is_risky' => true, 'reason' => $reason],
            );

            return true;
        } catch (\Throwable $e) {
            // Never let list maintenance break a sync — a missing entry only means
            // the update engine falls back to its other signals.
            Log::warning("Risky-plugin flagging failed for site {$site->id} / {$slug}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Would this slug land on the risk list? Used by the connect screen to show
     * the proposed profile before anything is written (SPEC §10 step 4).
     */
    public function wouldFlag(string $slug): bool
    {
        return $this->categoryFor(strtolower($slug)) !== null;
    }

    private function categoryFor(string $slug): ?string
    {
        foreach (self::CATEGORIES as $category => $fragments) {
            foreach ($fragments as $fragment) {
                if (str_contains($slug, $fragment)) {
                    return $category;
                }
            }
        }

        return null;
    }
}
