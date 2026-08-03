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
];
