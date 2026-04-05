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
