<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SettingsService;
use Illuminate\Support\ServiceProvider;

/**
 * Hydrates the audit AI config from the encrypted, DB-stored integration key
 * (Settings → Integrations → Anthropic) when no env-provided key is set — so the
 * audit AI tier and D4 use the same shared Anthropic credential as the rest of
 * the app (mirrors IncidentResponseConfigServiceProvider's fallback).
 *
 * Runs in app booted() so it overrides even a cached config.
 */
class AuditConfigServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->hydrate();
        });
    }

    /**
     * Set audit.ai.api_key from the shared integration key when it is not already
     * provided via the environment. Safe to call anytime.
     */
    public function hydrate(): void
    {
        // An env-provided key always wins — don't override it.
        if (config('audit.ai.api_key')) {
            return;
        }

        try {
            $encrypted = app(SettingsService::class)->get('ai_anthropic_api_key');
            if (! $encrypted) {
                return;
            }
            config(['audit.ai.api_key' => decrypt($encrypted)]);
        } catch (\Exception) {
            // DB unavailable (e.g. during migrations) or an undecryptable value — skip.
        }
    }
}
