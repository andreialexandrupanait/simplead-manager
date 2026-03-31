<?php

declare(strict_types=1);

namespace App\Livewire\Clients;

use App\Livewire\Forms\ClientFormData;
use App\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class ClientForm extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public ?Client $client = null;

    public ClientFormData $form;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $logo = null;

    public bool $removeLogo = false;

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

    public function updatedLogo(): void
    {
        $this->validate([
            'logo' => ['image', 'max:2048'],
        ]);
    }

    public function removeLogo(): void
    {
        $this->removeLogo = true;
        $this->logo = null;
    }

    public function save(): void
    {
        $validated = $this->form->validate();

        if ($this->logo) {
            $this->validate(['logo' => ['image', 'max:2048']]);
        }

        if ($this->client) {
            $this->authorize('update', $this->client);
            $this->client->update($validated);
            $this->handleLogoUpload($this->client);
            session()->flash('success', 'Client updated successfully.');
        } else {
            $this->authorize('create', Client::class);
            $client = Client::create($validated);
            $this->handleLogoUpload($client);
            session()->flash('success', 'Client created successfully.');
            $this->redirect(route('clients.show', $client), navigate: true);

            return;
        }

        $this->redirect(route('clients.show', $this->client), navigate: true);
    }

    protected function handleLogoUpload(Client $client): void
    {
        if ($this->removeLogo && ! $this->logo) {
            if ($client->logo) {
                Storage::disk('public')->delete($client->logo);
            }
            $client->update(['logo' => null]);

            return;
        }

        if ($this->logo) {
            if ($client->logo) {
                Storage::disk('public')->delete($client->logo);
            }
            $path = $this->logo->store("client-logos/{$client->id}", 'public');
            $client->update(['logo' => $path]);
        }
    }

    public function render()
    {
        $title = $this->client ? 'Edit Client' : 'Add Client';

        return view('livewire.clients.client-form')
            ->layout('components.layouts.app', ['title' => $title]);
    }
}
