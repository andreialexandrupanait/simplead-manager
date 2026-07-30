<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\SafeUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fleet bulk-update fan-out (SPEC §2 principle 2 / §4.4): for ONE site, queue a
 * safe update (backup → update → health-check → auto-rollback) for every pending
 * plugin and theme. Runs only on connected, safe-updates-enabled sites; the
 * caller (SitesList::bulkUpdate) skips the rest, so a fleet action can never
 * trigger an unprotected inline update.
 */
class QueueSiteSafeUpdatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Site $site,
        public readonly ?int $userId = null,
    ) {}

    public function handle(SafeUpdateService $safeUpdates): void
    {
        if (! $this->site->is_connected || ! $this->site->safe_updates_enabled) {
            return;
        }

        $plugins = $this->site->sitePlugins()->where('has_update', true)->get();
        $themes = $this->site->siteThemes()->where('has_update', true)->get();

        foreach ($plugins as $plugin) {
            $safeUpdate = $safeUpdates->createSafeUpdate(
                $this->site,
                'plugin',
                $plugin->slug,
                $plugin->name,
                $plugin->version ?? '',
                $plugin->update_version ?? '',
                $plugin->file,
            );
            RunSafeUpdate::dispatch($safeUpdate, $this->userId);
        }

        foreach ($themes as $theme) {
            $safeUpdate = $safeUpdates->createSafeUpdate(
                $this->site,
                'theme',
                $theme->slug,
                $theme->name,
                $theme->version ?? '',
                $theme->update_version ?? '',
            );
            RunSafeUpdate::dispatch($safeUpdate, $this->userId);
        }
    }
}
