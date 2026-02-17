<?php

namespace App\Providers;

use App\Services\SettingsService;
use Illuminate\Support\ServiceProvider;

class MailConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->app->booted(function () {
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
}
