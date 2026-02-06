<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Livewire\Component;

class ClientForm extends Component
{
    public ?Client $client = null;

    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $company = '';
    public string $website = '';
    public string $address = '';
    public string $city = '';
    public string $country = '';
    public string $vat_number = '';
    public string $registration_number = '';
    public string $notes = '';
    public string $status = 'active';

    public function mount(?Client $client = null): void
    {
        if ($client?->exists) {
            $this->client = $client;
            $this->name = $client->name ?? '';
            $this->email = $client->email ?? '';
            $this->phone = $client->phone ?? '';
            $this->company = $client->company ?? '';
            $this->website = $client->website ?? '';
            $this->address = $client->address ?? '';
            $this->city = $client->city ?? '';
            $this->country = $client->country ?? '';
            $this->vat_number = $client->vat_number ?? '';
            $this->registration_number = $client->registration_number ?? '';
            $this->notes = $client->notes ?? '';
            $this->status = $client->status ?? 'active';
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'in:active,inactive,archived'],
        ];
    }

    public function save(): void
    {
        $validated = $this->validate();

        if ($this->client) {
            $this->client->update($validated);
            session()->flash('success', 'Client updated successfully.');
        } else {
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
