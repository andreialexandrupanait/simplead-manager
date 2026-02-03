<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Livewire\Component;

class ClientDetail extends Component
{
    public Client $client;

    public function mount(Client $client): void
    {
        $this->client = $client;
    }

    public function render()
    {
        return view('livewire.clients.client-detail')
            ->layout('components.layouts.app', ['title' => $this->client->name]);
    }
}
