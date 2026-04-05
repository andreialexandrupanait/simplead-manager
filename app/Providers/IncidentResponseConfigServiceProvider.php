<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SettingsService;
use Illuminate\Support\ServiceProvider;

class IncidentResponseConfigServiceProvider extends ServiceProvider
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
                $irSettings = $settings->getGroup('ai_incident_response');

                if (empty($irSettings)) {
                    return;
                }

                if (isset($irSettings['ir_enabled'])) {
                    config(['incident-response.enabled' => (bool) $irSettings['ir_enabled']]);
                }

                if (isset($irSettings['ir_model'])) {
                    config(['incident-response.ai.model' => $irSettings['ir_model']]);
                }

                if (isset($irSettings['ir_api_key'])) {
                    try {
                        config(['incident-response.ai.api_key' => decrypt($irSettings['ir_api_key'])]);
                    } catch (\Exception) {
                        // Invalid encrypted value, skip
                    }
                }

                if (isset($irSettings['ir_max_actions_per_incident'])) {
                    config(['incident-response.safety.max_actions_per_incident' => (int) $irSettings['ir_max_actions_per_incident']]);
                }

                if (isset($irSettings['ir_max_ai_calls_per_incident'])) {
                    config(['incident-response.safety.max_ai_calls_per_incident' => (int) $irSettings['ir_max_ai_calls_per_incident']]);
                }

                if (isset($irSettings['ir_cooldown_minutes'])) {
                    config(['incident-response.safety.cooldown_minutes' => (int) $irSettings['ir_cooldown_minutes']]);
                }

                if (isset($irSettings['ir_max_incidents_per_site_per_hour'])) {
                    config(['incident-response.safety.max_incidents_per_site_per_hour' => (int) $irSettings['ir_max_incidents_per_site_per_hour']]);
                }

                if (isset($irSettings['ir_playbook_first'])) {
                    config(['incident-response.routing.playbook_first' => (bool) $irSettings['ir_playbook_first']]);
                }

                if (isset($irSettings['ir_ai_fallback'])) {
                    config(['incident-response.routing.ai_fallback' => (bool) $irSettings['ir_ai_fallback']]);
                }
            } catch (\Exception) {
                // DB may not be available during migrations
            }
        });
    }
}
