<?php

namespace App\Livewire\Clients;

use App\Livewire\Forms\ClientFormData;
use App\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ClientForm extends Component
{
    use AuthorizesRequests;

    public ?Client $client = null;

    public ClientFormData $form;

    public function mount(?Client $client = null): void
    {
        if ($client?->exists) {
            $this->authorize('update', $client);
            $this->client = $client;
            $this->form->setFromClient($client);
        } else {
            $this->authorize('create', Client::class);
        }
    }

    public function save(): void
    {
        $validated = $this->form->validate();

        if ($this->client) {
            $this->authorize('update', $this->client);
            $this->client->update($validated);
            session()->flash('success', 'Client updated successfully.');
        } else {
            $this->authorize('create', Client::class);
            $client = Client::create($validated);
            session()->flash('success', 'Client created successfully.');
            $this->redirect(route('clients.show', $client), navigate: true);
            return;
        }

        $this->redirect(route('clients.show', $this->client), navigate: true);
    }

    public function render()
    {
        $title = $this->client ? 'Edit Client' : 'Add Client';

        return view('livewire.clients.client-form')
            ->layout('components.layouts.app', ['title' => $title]);
    }
}
