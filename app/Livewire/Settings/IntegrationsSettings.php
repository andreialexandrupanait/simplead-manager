<?php

namespace App\Livewire\Settings;

use App\Models\CloudflareConnection;
use App\Models\GoogleConnection;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\CloudflareService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class IntegrationsSettings extends Component
{
    public bool $showDisconnectModal = false;
    public ?int $disconnectingId = null;

    // Cloudflare
    public string $cfApiToken = '';
    public ?int $deletingCfId = null;

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

            session()->flash('storage-success', "Connection test for {$destination->name} " . ($passed ? 'passed.' : 'failed.'));
        } catch (\Exception $e) {
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
        session()->flash('storage-success', "Storage destination deleted.");
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
                if (!empty($zones) && isset($zones[0]['account'])) {
                    $connection->update([
                        'account_id' => $zones[0]['account']['id'] ?? null,
                        'account_email' => $zones[0]['account']['name'] ?? null,
                    ]);
                }
                session()->flash('success', 'Cloudflare connection added and verified.');
            } else {
                session()->flash('error', 'Cloudflare token validation failed. The connection was saved but may not work.');
            }
        } catch (\Exception $e) {
            session()->flash('error', 'Failed to validate token: ' . $e->getMessage());
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

            session()->flash('success', "Cloudflare connection test " . ($valid ? 'passed' : 'failed') . '.');
        } catch (\Exception $e) {
            session()->flash('error', 'Test failed: ' . $e->getMessage());
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
        $connections = GoogleConnection::all()->map(function ($conn) {
            $conn->sites_using = $conn->analyticsConnections()->count()
                + $conn->searchConsoleConnections()->count();
            return $conn;
        });

        return view('livewire.settings.integrations-settings', [
            'connections' => $connections,
        ])->layout('components.layouts.app', ['title' => 'Integrations']);
    }
}
