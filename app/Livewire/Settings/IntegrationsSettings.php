<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\CloudflareConnection;
use App\Models\GoogleConnection;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\CloudflareService;
use App\Services\SettingsService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\RequestException;
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

    // Cloudflare
    public string $cfApiToken = '';

    public ?int $deletingCfId = null;

    // Postmark
    public string $postmarkServerToken = '';

    public string $postmarkDefaultStream = 'outbound';

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

        $this->googleClientId = $settings->get('google_client_id') ?? '';

        $encrypted = $settings->get('google_client_secret');
        if ($encrypted) {
            try {
                $this->googleClientSecret = decrypt($encrypted);
            } catch (DecryptException $e) {
                $this->googleClientSecret = '';
            }
        }

        // Postmark
        $encryptedPostmark = $settings->get('postmark_server_token');
        if ($encryptedPostmark) {
            try {
                $this->postmarkServerToken = decrypt($encryptedPostmark);
            } catch (DecryptException $e) {
                $this->postmarkServerToken = '';
            }
        }
        $this->postmarkDefaultStream = $settings->get('postmark_default_stream') ?? 'outbound';
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

    // Postmark methods

    public function savePostmarkCredentials(): void
    {
        $this->validate([
            'postmarkServerToken' => 'required|string|min:10',
            'postmarkDefaultStream' => 'required|in:outbound,broadcast',
        ], [
            'postmarkServerToken.required' => 'Server API Token is required.',
        ]);

        $settings = app(SettingsService::class);

        $settings->set('postmark_server_token', encrypt(trim($this->postmarkServerToken)), 'postmark');
        $settings->set('postmark_default_stream', $this->postmarkDefaultStream, 'postmark');

        session()->flash('success', 'Postmark credentials saved.');
        $this->dispatch('close-modal-configure-postmark');
    }

    public function clearPostmarkCredentials(): void
    {
        $settings = app(SettingsService::class);

        $settings->set('postmark_server_token', '', 'postmark');
        $settings->set('postmark_default_stream', 'outbound', 'postmark');

        $this->postmarkServerToken = '';
        $this->postmarkDefaultStream = 'outbound';

        session()->flash('success', 'Postmark credentials removed.');
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
