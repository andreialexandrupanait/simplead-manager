<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SettingsService;
use Illuminate\Support\ServiceProvider;

class MailConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Artisan commands during which the database overrides must NOT be applied,
     * because whatever they set would be frozen into a file.
     *
     * `config:cache` boots a fresh application and serialises the resulting
     * config array — including anything a `booted()` callback wrote. The entry
     * point runs it on every container start, so the mail credentials that
     * happened to be in the database at that moment were baked into
     * bootstrap/cache/config.php. At runtime this is invisible, because the
     * provider re-applies the same values on top. It stops being invisible the
     * moment a setting is REMOVED: nothing overrides the baked copy any more,
     * and the app keeps using a credential that no longer exists anywhere,
     * until the container is restarted. Diagnosed 2026-08-07, when deleting the
     * SMTP username had no effect and alerts kept authenticating as the wrong
     * account.
     */
    private const CACHE_BUILDING_COMMANDS = ['config:cache', 'optimize'];

    public function boot(): void
    {
        $this->app->booted(function () {
            if ($this->isBuildingTheConfigCache()) {
                return;
            }

            try {
                $settings = app(SettingsService::class);
                $mailSettings = $settings->getGroup('mail');

                if (empty($mailSettings)) {
                    return;
                }

                if (isset($mailSettings['mail.mailer'])) {
                    config(['mail.default' => $mailSettings['mail.mailer']]);
                }

                if (isset($mailSettings['mail.host'])) {
                    config(['mail.mailers.smtp.host' => $mailSettings['mail.host']]);
                }

                if (isset($mailSettings['mail.port'])) {
                    config(['mail.mailers.smtp.port' => (int) $mailSettings['mail.port']]);
                }

                if (isset($mailSettings['mail.username'])) {
                    config(['mail.mailers.smtp.username' => $mailSettings['mail.username']]);
                }

                if (isset($mailSettings['mail.password'])) {
                    try {
                        config(['mail.mailers.smtp.password' => decrypt($mailSettings['mail.password'])]);
                    } catch (\Exception $e) {
                        // Invalid encrypted value, skip
                    }
                }

                if (isset($mailSettings['mail.encryption'])) {
                    $scheme = $mailSettings['mail.encryption'] === 'ssl' ? 'smtps' : null;
                    config(['mail.mailers.smtp.scheme' => $scheme]);
                }

                if (isset($mailSettings['mail.from_address'])) {
                    config(['mail.from.address' => $mailSettings['mail.from_address']]);
                }

                if (isset($mailSettings['mail.from_name'])) {
                    config(['mail.from.name' => $mailSettings['mail.from_name']]);
                }
            } catch (\Exception $e) {
                // DB may not be available during migrations
            }
        });
    }

    private function isBuildingTheConfigCache(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        $command = $_SERVER['argv'][1] ?? null;

        return is_string($command) && in_array($command, self::CACHE_BUILDING_COMMANDS, true);
    }
}
