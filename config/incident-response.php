<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Incident Response
    |--------------------------------------------------------------------------
    |
    | Master switch for the AI incident response system. When disabled,
    | no automatic diagnosis or remediation will be triggered.
    |
    */

    'enabled' => env('INCIDENT_RESPONSE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Claude AI Configuration
    |--------------------------------------------------------------------------
    */

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('INCIDENT_RESPONSE_MODEL', 'claude-sonnet-4-20250514'),
        'max_tokens' => 4096,
        'temperature' => 0.1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety Guardrails
    |--------------------------------------------------------------------------
    */

    'safety' => [
        'max_actions_per_incident' => 10,
        'max_ai_calls_per_incident' => 5,
        'cooldown_minutes' => 30,
        // Upper bound (minutes) for the exponential per-trigger backoff applied to
        // persistently-failing incidents so a stuck trigger cannot loop the pipeline
        // every cooldown window forever (P0-21). Defaults to 24h.
        'max_backoff_minutes' => 1440,
        'max_incidents_per_site_per_hour' => 3,
        'always_backup_before_destructive' => true,
        'destructive_actions' => [
            'deactivate_plugin',
            'rollback_plugin',
            'update_plugin',
            'update_core',
            'db_cleanup',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tier Routing
    |--------------------------------------------------------------------------
    */

    'routing' => [
        'playbook_first' => true,
        'ai_fallback' => true,
    ],

];
