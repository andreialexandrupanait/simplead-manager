<?php

declare(strict_types=1);

namespace App\Livewire\Clients;

use App\Models\Client;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Component;

class ClientDetail extends Component
{
    use AuthorizesRequests;

    public Client $client;

    public function mount(Client $client): void
    {
        $this->authorize('view', $client);
        $this->client = $client->load('sites');
    }

    public function confirmDelete(): void
    {
        $this->dispatch('open-modal-delete-client');
    }

    public function togglePortal(): void
    {
        $this->authorize('update', $this->client);

        if (! $this->client->portal_token) {
            $this->client->portal_token = Str::random(64);
        }

        $this->client->portal_enabled = ! $this->client->portal_enabled;
        $this->client->save();

        // P3-25: enabling/disabling a public portal is a security-relevant change
        // — leave an audit trail like other sensitive actions.
        $this->client->portal_enabled
            ? ActivityLogger::clientPortalEnabled($this->client)
            : ActivityLogger::clientPortalDisabled($this->client);
    }

    public function regeneratePortalToken(): void
    {
        $this->authorize('update', $this->client);
        $this->client->update(['portal_token' => Str::random(64)]);

        // P3-25: token rotation invalidates the old public link — record it.
        ActivityLogger::clientPortalTokenRegenerated($this->client);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->client);
        $this->client->delete();
        session()->flash('success', 'Client deleted successfully.');
        $this->redirect(route('clients.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.clients.client-detail')
            ->layout('components.layouts.app', ['title' => $this->client->name]);
    }
}
