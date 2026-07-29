<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\WooCheck;
use App\Services\Notifications\NotificationService;
use App\Services\WooHealthService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Faza 5.2 — daily WooCommerce store-health sweep (conditional).
 *
 * Runs ONLY against connected sites whose plugin inventory already shows an active
 * 'woocommerce' plugin — detection is free and lives in SitePlugin, so this job adds
 * no detection of its own. For each such site it calls the connector's /woo-health
 * endpoint, maps the signals via WooHealthService, upserts the per-site woo_checks
 * row, and alerts on the traffic-light transition:
 *   red    → a payment gateway is down (enabled-but-unavailable / none enabled)
 *   yellow → stuck pending orders over threshold, or scheduled-sales cron missing
 *   green  → nominal (no alert)
 *
 * Alerting is state-change based (only when the colour differs from the last stored
 * check) so a still-broken store is not re-alerted every morning. A site whose
 * connector reports applicable=false (WooCommerce deactivated since the last sync)
 * is stored as applicable=false and never alerts.
 */
class CheckWooHealth implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    /** Age (hours) beyond which a still-pending order counts as stuck. */
    private const PENDING_THRESHOLD_HOURS = 24;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'check-woo-health';
    }

    public function handle(): void
    {
        Site::query()
            ->where('is_connected', true)
            ->whereHas('sitePlugins', fn ($q) => $q
                ->where('slug', 'woocommerce')
                ->where('is_active', true))
            ->chunkById(200, function ($sites): void {
                foreach ($sites as $site) {
                    $this->processSite($site);
                }
            });
    }

    private function processSite(Site $site): void
    {
        try {
            $api = app(WordPressApiServiceFactory::class)->make($site);
            $response = $api->request('GET', '/woo-health', [], [
                'pending_hours' => self::PENDING_THRESHOLD_HOURS,
            ]);
        } catch (\Throwable $e) {
            // A transient connector failure must not erase the last-known signals —
            // record the error only and leave the prior woo_checks row intact.
            Log::warning('CheckWooHealth: connector request failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            $this->recordError($site, $e->getMessage());

            return;
        }

        if (! $response->successful()) {
            Log::warning('CheckWooHealth: connector returned error', [
                'site_id' => $site->id,
                'status' => $response->status(),
            ]);
            $this->recordError($site, 'HTTP '.$response->status());

            return;
        }

        // checkout_status stays NULL for now — a Manager-side GET on the store's
        // checkout URL is an optional future signal (see class docblock). When wired,
        // pass its HTTP status as the second arg to evaluate().
        $signals = WooHealthService::evaluate($response->json() ?? [], null);

        $previous = WooCheck::where('site_id', $site->id)->first();
        $previousColor = $previous?->applicable ? $previous->getStatusColorAttribute() : null;

        $check = WooCheck::updateOrCreate(
            ['site_id' => $site->id],
            [
                'applicable' => $signals['applicable'],
                'checkout_status' => $signals['checkout_status'],
                'gateways_ok' => $signals['gateways_ok'],
                'pending_orders_count' => $signals['pending_orders_count'],
                'pending_over_threshold' => $signals['pending_over_threshold'],
                'discount_cron_scheduled' => $signals['discount_cron_scheduled'],
                'gateways' => $signals['gateways'],
                'last_error' => null,
                'checked_at' => now(),
            ],
        );

        // Non-applicable (Woo deactivated since sync) → clean skip, no alert.
        if (! $signals['applicable'] || $signals['severity'] === null) {
            return;
        }

        // State-change dedup: only alert when the traffic light actually changes,
        // so an unresolved red/yellow is not re-sent every day.
        if ($signals['color'] === $previousColor) {
            return;
        }

        $this->notify($site, $check, $signals);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function notify(Site $site, WooCheck $check, array $signals): void
    {
        $reasons = implode(', ', $signals['reasons']);
        $summary = "\xF0\x9F\x9B\x92 WooCommerce · *{$site->name}* — {$reasons}.";

        NotificationService::notifySiteEventSlim(
            site: $site,
            event: 'woo_health',
            summary: $summary,
            deepLink: '<'.route('sites.overview', $site).'|Open site →>',
            severity: $signals['severity'],
        );
    }

    private function recordError(Site $site, string $message): void
    {
        $existing = WooCheck::where('site_id', $site->id)->first();

        if ($existing === null) {
            WooCheck::create([
                'site_id' => $site->id,
                'applicable' => true,
                'last_error' => $message,
                'checked_at' => now(),
            ]);

            return;
        }

        $existing->updateQuietly([
            'last_error' => $message,
            'checked_at' => now(),
        ]);
    }
}
