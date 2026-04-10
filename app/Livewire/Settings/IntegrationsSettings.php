<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\CloudflareConnection;
use App\Models\GoogleConnection;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\CloudflareService;
use App\Services\OpenApiService;
use App\Services\SettingsService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class IntegrationsSettings extends Component
{
    public bool $showDisconnectModal = false;

    public ?int $disconnectingId = null;

    // Dropbox API Credentials
    public string $dropboxAppKey = '';

    public string $dropboxAppSecret = '';

    // Google API Credentials
    public string $googleClientId = '';

    public string $googleClientSecret = '';

    // Unsplash
    public string $unsplashAccessKey = '';

    // OpenAPI.ro
    public string $openApiKey = '';

    // AI Providers
    public string $anthropicApiKey = '';

    public string $openAiApiKey = '';

    // Cloudflare
    public string $cfApiToken = '';

    public ?int $deletingCfId = null;

    public function mount(): void
    {
        $settings = app(SettingsService::class);

        $this->dropboxAppKey = $settings->get('dropbox_app_key') ?? '';

        $encryptedDropbox = $settings->get('dropbox_app_secret');
        if ($encryptedDropbox) {
            try {
                $this->dropboxAppSecret = decrypt($encryptedDropbox);
            } catch (DecryptException $e) {
                $this->dropboxAppSecret = '';
            }
        }

        $this->unsplashAccessKey = $settings->get('unsplash_access_key') ?? '';

        $encryptedOpenApi = $settings->get('openapi_key');
        if ($encryptedOpenApi) {
            try {
                $this->openApiKey = decrypt($encryptedOpenApi);
            } catch (DecryptException $e) {
                $this->openApiKey = '';
            }
        }

        $this->googleClientId = $settings->get('google_client_id') ?? '';

        $encrypted = $settings->get('google_client_secret');
        if ($encrypted) {
            try {
                $this->googleClientSecret = decrypt($encrypted);
            } catch (DecryptException $e) {
                $this->googleClientSecret = '';
            }
        }

        $encryptedAnthropic = $settings->get('ai_anthropic_api_key');
        if ($encryptedAnthropic) {
            try {
                $this->anthropicApiKey = decrypt($encryptedAnthropic);
            } catch (DecryptException $e) {
                $this->anthropicApiKey = '';
            }
        }

        $encryptedOpenAi = $settings->get('ai_openai_api_key');
        if ($encryptedOpenAi) {
            try {
                $this->openAiApiKey = decrypt($encryptedOpenAi);
            } catch (DecryptException $e) {
                $this->openAiApiKey = '';
            }
        }
    }

    public function saveDropboxCredentials(): void
    {
        $this->validate([
            'dropboxAppKey' => 'required|string|min:10',
            'dropboxAppSecret' => 'required|string|min:10',
        ], [
            'dropboxAppKey.required' => 'App Key is required.',
            'dropboxAppSecret.required' => 'App Secret is required.',
        ]);

        $settings = app(SettingsService::class);

        $settings->set('dropbox_app_key', trim($this->dropboxAppKey), 'dropbox');
        $settings->set('dropbox_app_secret', encrypt(trim($this->dropboxAppSecret)), 'dropbox');

        config([
            'services.dropbox.app_key' => trim($this->dropboxAppKey),
            'services.dropbox.app_secret' => trim($this->dropboxAppSecret),
        ]);

        session()->flash('success', 'Dropbox API credentials saved.');
        $this->dispatch('close-modal-configure-dropbox');
    }

    public function saveUnsplashCredentials(): void
    {
        $this->validate([
            'unsplashAccessKey' => 'required|string|min:10',
        ]);

        $settings = app(SettingsService::class);
        $settings->set('unsplash_access_key', trim($this->unsplashAccessKey), 'unsplash');

        config(['services.unsplash.access_key' => trim($this->unsplashAccessKey)]);

        \Illuminate\Support\Facades\Cache::forget('unsplash_slide_images');

        session()->flash('success', 'Unsplash API credentials saved.');
        $this->dispatch('close-modal-configure-unsplash');
    }

    public function saveOpenApiCredentials(): void
    {
        $this->validate([
            'openApiKey' => 'required|string|min:10',
        ]);

        $settings = app(SettingsService::class);
        $settings->set('openapi_key', encrypt(trim($this->openApiKey)), 'openapi');

        session()->flash('success', __('OpenAPI.ro API key saved.'));
        $this->dispatch('close-modal-configure-openapi');
    }

    public function testOpenApiConnection(): void
    {
        try {
            $success = app(OpenApiService::class)->testConnection();

            if ($success) {
                session()->flash('success', __('OpenAPI.ro connection successful!'));
            } else {
                session()->flash('error', __('OpenAPI.ro connection test failed.'));
            }
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function saveGoogleCredentials(): void
    {
        $this->validate([
            'googleClientId' => 'required|string|min:10',
            'googleClientSecret' => 'required|string|min:10',
        ], [
            'googleClientId.required' => 'Client ID is required.',
            'googleClientSecret.required' => 'Client Secret is required.',
        ]);

        $settings = app(SettingsService::class);

        $settings->set('google_client_id', trim($this->googleClientId), 'google');
        $settings->set('google_client_secret', encrypt(trim($this->googleClientSecret)), 'google');

        // Update runtime config so it takes effect immediately
        config([
            'services.google.client_id' => trim($this->googleClientId),
            'services.google.client_secret' => trim($this->googleClientSecret),
        ]);

        session()->flash('success', 'Google API credentials saved.');
        $this->dispatch('close-modal-configure-google');
    }

    #[Computed]
    public function destinations()
    {
        return StorageDestination::orderBy('name')->get();
    }

    public function testDestination(int $id): void
    {
        $destination = StorageDestination::findOrFail($id);

        try {
            $driver = StorageFactory::make($destination);
            $passed = $driver->test();

            $destination->update([
                'last_tested_at' => now(),
                'last_test_passed' => $passed,
                'last_test_error' => $passed ? null : 'Test returned false.',
            ]);

            session()->flash('storage-success', "Connection test for {$destination->name} ".($passed ? 'passed.' : 'failed.'));
        } catch (RequestException|\RuntimeException $e) {
            $destination->update([
                'last_tested_at' => now(),
                'last_test_passed' => false,
                'last_test_error' => $e->getMessage(),
            ]);

            session()->flash('storage-error', "Connection test for {$destination->name} failed: {$e->getMessage()}");
        }
    }

    public function setDefault(int $id): void
    {
        StorageDestination::where('is_default', true)->update(['is_default' => false]);
        StorageDestination::findOrFail($id)->update(['is_default' => true]);
    }

    public function deleteDestination(int $id): void
    {
        $destination = StorageDestination::findOrFail($id);

        if ($destination->backups()->exists()) {
            session()->flash('storage-error', "Cannot delete {$destination->name} — it has existing backups.");

            return;
        }

        $destination->delete();
        session()->flash('storage-success', 'Storage destination deleted.');
    }

    #[On('storage-destination-saved')]
    public function refreshList(): void
    {
        unset($this->destinations);
    }

    public function addAccount(): void
    {
        $this->redirect(route('google.auth', ['return_url' => route('settings.integrations')]));
    }

    public function confirmDisconnect(int $id): void
    {
        $this->disconnectingId = $id;
        $this->dispatch('open-modal-disconnect-google');
    }

    public function disconnectAccount(): void
    {
        if ($this->disconnectingId) {
            GoogleConnection::find($this->disconnectingId)?->delete();
        }

        $this->dispatch('close-modal-disconnect-google');
        $this->disconnectingId = null;

        session()->flash('success', 'Google account disconnected.');
    }

    // AI Provider methods

    public function saveAnthropicCredentials(): void
    {
        $this->validate([
            'anthropicApiKey' => 'required|string|min:10',
        ]);

        $settings = app(SettingsService::class);
        $settings->set('ai_anthropic_api_key', encrypt(trim($this->anthropicApiKey)), 'ai_providers');

        session()->flash('success', __('Anthropic API key saved.'));
        $this->dispatch('close-modal-configure-anthropic');
    }

    public function testAnthropicConnection(): void
    {
        if (! $this->anthropicApiKey) {
            session()->flash('error', __('Please enter an API key first.'));

            return;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->anthropicApiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(15)->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 10,
                'messages' => [['role' => 'user', 'content' => 'Say "ok"']],
            ]);

            if ($response->successful()) {
                session()->flash('success', __('Anthropic connection successful!'));
            } else {
                $error = $response->json('error.message', 'Unknown error');
                session()->flash('error', __('Anthropic connection failed: :error', ['error' => $error]));
            }
        } catch (\Throwable $e) {
            session()->flash('error', __('Connection failed: :error', ['error' => $e->getMessage()]));
        }
    }

    public function saveOpenAiCredentials(): void
    {
        $this->validate([
            'openAiApiKey' => 'required|string|min:10',
        ]);

        $settings = app(SettingsService::class);
        $settings->set('ai_openai_api_key', encrypt(trim($this->openAiApiKey)), 'ai_providers');

        session()->flash('success', __('OpenAI API key saved.'));
        $this->dispatch('close-modal-configure-openai-ai');
    }

    public function testOpenAiConnection(): void
    {
        if (! $this->openAiApiKey) {
            session()->flash('error', __('Please enter an API key first.'));

            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->openAiApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'max_tokens' => 10,
                'messages' => [['role' => 'user', 'content' => 'Say "ok"']],
            ]);

            if ($response->successful()) {
                session()->flash('success', __('OpenAI connection successful!'));
            } else {
                $error = $response->json('error.message', 'Unknown error');
                session()->flash('error', __('OpenAI connection failed: :error', ['error' => $error]));
            }
        } catch (\Throwable $e) {
            session()->flash('error', __('Connection failed: :error', ['error' => $e->getMessage()]));
        }
    }

    // Cloudflare methods

    #[Computed]
    public function cloudflareConnections()
    {
        return CloudflareConnection::orderBy('created_at')->get();
    }

    public function addCloudflareConnection(): void
    {
        $this->validate([
            'cfApiToken' => 'required|string|min:10',
        ]);

        $connection = CloudflareConnection::create([
            'user_id' => auth()->id(),
            'api_token' => $this->cfApiToken,
        ]);

        try {
            $service = new CloudflareService($connection);
            $valid = $service->validateToken();

            if ($valid) {
                // Try to get account info from zones
                $zones = $service->listZones();
                if (! empty($zones) && isset($zones[0]['account'])) {
                    $connection->update([
                        'account_id' => $zones[0]['account']['id'] ?? null,
                        'account_email' => $zones[0]['account']['name'] ?? null,
                    ]);
                }
                session()->flash('success', 'Cloudflare connection added and verified.');
            } else {
                session()->flash('error', 'Cloudflare token validation failed. The connection was saved but may not work.');
            }
        } catch (RequestException|\RuntimeException $e) {
            session()->flash('error', 'Failed to validate token: '.$e->getMessage());
        }

        $this->cfApiToken = '';
        unset($this->cloudflareConnections);
    }

    public function testCloudflareConnection(int $id): void
    {
        $connection = CloudflareConnection::findOrFail($id);

        try {
            $service = new CloudflareService($connection);
            $valid = $service->validateToken();

            session()->flash('success', 'Cloudflare connection test '.($valid ? 'passed' : 'failed').'.');
        } catch (RequestException|\RuntimeException $e) {
            session()->flash('error', 'Test failed: '.$e->getMessage());
        }

        unset($this->cloudflareConnections);
    }

    public function confirmDeleteCloudflare(int $id): void
    {
        $this->deletingCfId = $id;
        $this->dispatch('open-modal-delete-cloudflare');
    }

    public function deleteCloudflareConnection(): void
    {
        if ($this->deletingCfId) {
            CloudflareConnection::find($this->deletingCfId)?->delete();
        }

        $this->dispatch('close-modal-delete-cloudflare');
        $this->deletingCfId = null;
        unset($this->cloudflareConnections);
        session()->flash('success', 'Cloudflare connection deleted.');
    }

    public function render()
    {
        $connections = GoogleConnection::withCount(['analyticsConnections', 'searchConsoleConnections'])
            ->get()
            ->each(function ($conn) {
                $conn->sites_using = $conn->analytics_connections_count + $conn->search_console_connections_count;
            });

        return view('livewire.settings.integrations-settings', [
            'connections' => $connections,
        ])->layout('components.layouts.app', ['title' => 'Integrations']);
    }
}
