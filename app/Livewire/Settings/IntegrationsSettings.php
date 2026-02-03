<?php

namespace App\Livewire\Settings;

use App\Models\GoogleConnection;
use Livewire\Component;

class IntegrationsSettings extends Component
{
    public bool $showDisconnectModal = false;
    public ?int $disconnectingId = null;

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
