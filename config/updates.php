<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Smart update rules (Faza 6)
    |--------------------------------------------------------------------------
    |
    | Master flag for the smart update-decision engine. When FALSE (the
    | default) the safe-update flow behaves exactly as before: every queued
    | plugin update is dispatched immediately. When TRUE, plugin updates are
    | routed by App\Services\UpdateDecisionService onto one of three tracks
    | (auto-minor, await-approval, critical-bypass). Themes/core are never
    | affected by the engine.
    |
    */
    'smart_rules_enabled' => (bool) env('UPDATES_SMART_RULES_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Plugin risk assessment (AI tier)
    |--------------------------------------------------------------------------
    |
    | PluginRiskAssessmentService optionally asks Claude to judge how risky a
    | given plugin update looks, on top of the deterministic signals. Without a
    | key it simply skips that tier — the rest of the assessment still works.
    |
    | These two keys used to live in config/incident-response.php. That module
    | was deleted in Faza 1, but its config file and service provider survived
    | because this service read from it — leaving a load-bearing config named
    | after a feature that no longer exists. The key itself is entered on the
    | Integrations settings screen; the env var is the fallback.
    |
    */
    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('PLUGIN_RISK_AI_MODEL', 'claude-sonnet-4-5-20250929'),
    ],
];
