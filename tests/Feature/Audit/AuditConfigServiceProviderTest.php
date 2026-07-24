<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Providers\AuditConfigServiceProvider;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza D: the audit AI key resolves from the shared, DB-stored integration key
 * (Settings → Integrations → Anthropic) when no env key is set — so the key
 * entered in the UI drives the audit AI tier.
 */
class AuditConfigServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    private function hydrate(): void
    {
        (new AuditConfigServiceProvider($this->app))->hydrate();
    }

    public function test_it_hydrates_the_audit_key_from_the_integration_setting(): void
    {
        config(['audit.ai.api_key' => null]);
        app(SettingsService::class)->set('ai_anthropic_api_key', encrypt('sk-ant-from-db'), 'ai_providers');

        $this->hydrate();

        $this->assertSame('sk-ant-from-db', config('audit.ai.api_key'));
    }

    public function test_an_env_provided_key_wins_over_the_setting(): void
    {
        config(['audit.ai.api_key' => 'sk-ant-from-env']);
        app(SettingsService::class)->set('ai_anthropic_api_key', encrypt('sk-ant-from-db'), 'ai_providers');

        $this->hydrate();

        $this->assertSame('sk-ant-from-env', config('audit.ai.api_key'));
    }

    public function test_it_is_a_noop_when_no_key_is_stored(): void
    {
        config(['audit.ai.api_key' => null]);

        $this->hydrate();

        $this->assertNull(config('audit.ai.api_key'));
    }

    public function test_it_ignores_an_undecryptable_value(): void
    {
        config(['audit.ai.api_key' => null]);
        app(SettingsService::class)->set('ai_anthropic_api_key', 'not-encrypted-garbage', 'ai_providers');

        $this->hydrate();

        $this->assertNull(config('audit.ai.api_key'));
    }
}
