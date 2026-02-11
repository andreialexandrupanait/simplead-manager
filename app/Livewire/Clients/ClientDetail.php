<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Livewire\Component;

class ClientDetail extends Component
{
    public Client $client;
    public function mount(Client $client): void
    {
        $this->client = $client->load('sites');
    }

    public function confirmDelete(): void
    {
        $this->dispatch('open-modal-delete-client');
    }

    public function delete(): void
    {
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
