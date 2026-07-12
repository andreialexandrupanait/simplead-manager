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
        // Default to a current, non-retired Claude model. The previous defaults
        // (claude-sonnet-4-20250514 / claude-opus-4-20250514) were retired
        // 2026-06-15 — a request against them 404s and silently kills the AI tier.
        'model' => env('INCIDENT_RESPONSE_MODEL', 'claude-sonnet-4-5-20250929'),
        // Allowlist of selectable models — kept as the single source of truth for
        // both this config and the settings-screen validation so they cannot drift.
        'allowed_models' => [
            'claude-sonnet-4-5-20250929',
            'claude-opus-4-1-20250805',
            'claude-haiku-4-5-20251001',
        ],
        'max_tokens' => 4096,
        'temperature' => 0.1,
        // Bounded retry for the Claude API call: a transient 429/5xx or connection
        // error is retried with exponential backoff instead of silently killing the
        // AI tier on the first blip. 4xx (other than 429) are not retried.
        'max_attempts' => 3,
        'retry_base_delay_ms' => 500,
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

        // P1-46: mutating executor actions that change live-site state. These are
        // only executed when permitted by the triggering incident's playbook
        // allowlist (or the conservative default below when no playbook matched);
        // anything outside the allowlist is refused/escalated rather than run on
        // raw AI whim. Read-only/diagnostic actions are never gated.
        'mutating_actions' => [
            'deactivate_plugin',
            'activate_plugin',
            'update_plugin',
            'rollback_plugin',
            'apply_security_fix',
            'db_cleanup',
            'fix_elementor',
        ],

        // Conservative fallback allowlist used when the incident has no matching
        // playbook — the union of mutating actions the playbooks themselves use.
        // Deliberately excludes activate_plugin and rollback_plugin so those only
        // run when a playbook explicitly permits them.
        'default_allowed_actions' => [
            'deactivate_plugin',
            'update_plugin',
            'apply_security_fix',
            'db_cleanup',
            'fix_elementor',
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
