<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Services\SettingsService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Livewire\Component;

class AiIncidentResponseSettings extends Component
{
    // Master switch
    public bool $enabled = false;

    // AI Configuration
    public string $apiKey = '';

    public string $model = 'claude-sonnet-4-5-20250929';

    // Safety Guardrails
    public int $maxActionsPerIncident = 10;

    public int $maxAiCallsPerIncident = 5;

    public int $cooldownMinutes = 30;

    public int $maxIncidentsPerSitePerHour = 3;

    // Routing
    public bool $playbookFirst = true;

    public bool $aiFallback = true;

    // UI state
    public bool $testingConnection = false;

    public function mount(): void
    {
        $settings = app(SettingsService::class);

        $this->enabled = (bool) $settings->get('ir_enabled', false);
        $this->model = $settings->get('ir_model')
            ?? config('incident-response.ai.model', 'claude-sonnet-4-5-20250929');

        $encrypted = $settings->get('ir_api_key');
        if ($encrypted) {
            try {
                $this->apiKey = decrypt($encrypted);
            } catch (DecryptException) {
                $this->apiKey = '';
            }
        }

        $this->maxActionsPerIncident = (int) ($settings->get('ir_max_actions_per_incident') ?? 10);
        $this->maxAiCallsPerIncident = (int) ($settings->get('ir_max_ai_calls_per_incident') ?? 5);
        $this->cooldownMinutes = (int) ($settings->get('ir_cooldown_minutes') ?? 30);
        $this->maxIncidentsPerSitePerHour = (int) ($settings->get('ir_max_incidents_per_site_per_hour') ?? 3);
        $this->playbookFirst = (bool) ($settings->get('ir_playbook_first') ?? true);
        $this->aiFallback = (bool) ($settings->get('ir_ai_fallback') ?? true);
    }

    public function save(): void
    {
        $this->validate([
            'apiKey' => 'nullable|string|min:10',
            'model' => ['required', 'string', Rule::in(config('incident-response.ai.allowed_models', []))],
            'maxActionsPerIncident' => 'required|integer|min:1|max:50',
            'maxAiCallsPerIncident' => 'required|integer|min:1|max:20',
            'cooldownMinutes' => 'required|integer|min:5|max:1440',
            'maxIncidentsPerSitePerHour' => 'required|integer|min:1|max:20',
        ]);

        $settings = app(SettingsService::class);
        $group = 'ai_incident_response';

        $settings->set('ir_enabled', $this->enabled, $group, 'boolean');
        $settings->set('ir_model', $this->model, $group, 'string');

        if ($this->apiKey) {
            $settings->set('ir_api_key', encrypt($this->apiKey), $group, 'string');
        }

        $settings->set('ir_max_actions_per_incident', $this->maxActionsPerIncident, $group, 'integer');
        $settings->set('ir_max_ai_calls_per_incident', $this->maxAiCallsPerIncident, $group, 'integer');
        $settings->set('ir_cooldown_minutes', $this->cooldownMinutes, $group, 'integer');
        $settings->set('ir_max_incidents_per_site_per_hour', $this->maxIncidentsPerSitePerHour, $group, 'integer');
        $settings->set('ir_playbook_first', $this->playbookFirst, $group, 'boolean');
        $settings->set('ir_ai_fallback', $this->aiFallback, $group, 'boolean');

        // Update runtime config so changes take effect immediately
        config([
            'incident-response.enabled' => $this->enabled,
            'incident-response.ai.api_key' => $this->apiKey,
            'incident-response.ai.model' => $this->model,
            'incident-response.safety.max_actions_per_incident' => $this->maxActionsPerIncident,
            'incident-response.safety.max_ai_calls_per_incident' => $this->maxAiCallsPerIncident,
            'incident-response.safety.cooldown_minutes' => $this->cooldownMinutes,
            'incident-response.safety.max_incidents_per_site_per_hour' => $this->maxIncidentsPerSitePerHour,
            'incident-response.routing.playbook_first' => $this->playbookFirst,
            'incident-response.routing.ai_fallback' => $this->aiFallback,
        ]);

        session()->flash('success', __('AI Incident Response settings saved.'));
    }

    public function testConnection(): void
    {
        if (! $this->apiKey) {
            session()->flash('error', __('Please enter an API key first.'));

            return;
        }

        $this->testingConnection = true;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(15)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 10,
                'messages' => [['role' => 'user', 'content' => 'Say "ok"']],
            ]);

            if ($response->successful()) {
                session()->flash('success', __('Connection successful! Claude API is reachable.'));
            } else {
                $error = $response->json('error.message', 'Unknown error');
                session()->flash('error', __('Connection failed: :error', ['error' => $error]));
            }
        } catch (\Throwable $e) {
            session()->flash('error', __('Connection failed: :error', ['error' => $e->getMessage()]));
        }

        $this->testingConnection = false;
    }

    public function render()
    {
        return view('livewire.settings.ai-incident-response-settings')
            ->layout('components.layouts.app', ['title' => __('AI Incident Response')]);
    }
}
