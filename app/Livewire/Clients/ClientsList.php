<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Livewire\Component;
use Livewire\WithPagination;

class ClientsList extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $clients = Client::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->withCount('sites')
            ->latest()
            ->paginate(12);

        return view('livewire.clients.clients-list', compact('clients'))
            ->layout('components.layouts.app', ['title' => 'Clients']);
    }
}
