<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\AiIncidentResponseSettings;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-57: the decrypted Anthropic API key must never round-trip to the browser.
 * The field is write-only: masked on load, empty submit keeps the existing key,
 * a new value replaces it.
 */
class AiIncidentResponseSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const EXISTING_KEY = 'sk-ant-api03-existing-secret-key-value';

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function storeExistingKey(): void
    {
        app(SettingsService::class)->set('ir_api_key', encrypt(self::EXISTING_KEY), 'ai_incident_response', 'string');
    }

    public function test_decrypted_key_is_never_exposed_to_the_component_state(): void
    {
        $this->storeExistingKey();

        Livewire::actingAs($this->admin())
            ->test(AiIncidentResponseSettings::class)
            // The public property must NOT hold the decrypted key.
            ->assertSet('apiKey', '')
            // But the UI knows a key is set.
            ->assertSet('apiKeySet', true)
            // The plaintext key must not appear anywhere in the dehydrated payload.
            ->assertDontSee(self::EXISTING_KEY);
    }

    public function test_saving_with_empty_key_preserves_existing_key(): void
    {
        $this->storeExistingKey();

        Livewire::actingAs($this->admin())
            ->test(AiIncidentResponseSettings::class)
            ->set('enabled', true)
            ->set('apiKey', '') // left blank
            ->call('save')
            ->assertHasNoErrors();

        $stored = app(SettingsService::class)->get('ir_api_key');
        $this->assertSame(self::EXISTING_KEY, decrypt($stored));
    }

    public function test_saving_a_new_key_replaces_the_stored_key(): void
    {
        $this->storeExistingKey();
        $newKey = 'sk-ant-api03-brand-new-rotated-key';

        Livewire::actingAs($this->admin())
            ->test(AiIncidentResponseSettings::class)
            ->set('enabled', true)
            ->set('apiKey', $newKey)
            ->call('save')
            ->assertHasNoErrors()
            // After save the plaintext is flushed from the public property.
            ->assertSet('apiKey', '')
            ->assertSet('apiKeySet', true);

        $stored = app(SettingsService::class)->get('ir_api_key');
        $this->assertSame($newKey, decrypt($stored));
    }
}
