<?php

namespace App\Livewire\Clients;

use App\Livewire\Traits\WithSorting;
use App\Models\Client;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ClientsList extends Component
{
    use WithPagination;
    use WithSorting;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = 'all';

    public ?int $deletingId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->dispatch('open-modal-delete-client');
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->dispatch('close-modal-delete-client');
    }

    public function delete(): void
    {
        if ($this->deletingId) {
            Client::find($this->deletingId)?->delete();
            session()->flash('success', 'Client deleted successfully.');
        }

        $this->deletingId = null;
        $this->dispatch('close-modal-delete-client');
    }

    public function changeStatus(int $id, string $status): void
    {
        Client::where('id', $id)->update(['status' => $status]);
        session()->flash('success', 'Client status updated.');
    }

    #[Computed]
    public function statusCounts(): array
    {
        return [
            'all' => Client::count(),
            'active' => Client::where('status', 'active')->count(),
            'inactive' => Client::where('status', 'inactive')->count(),
            'archived' => Client::where('status', 'archived')->count(),
        ];
    }

    public function render()
    {
        $clients = Client::query()
            ->search($this->search)
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->withCount('sites')
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(12);

        return view('livewire.clients.clients-list', compact('clients'))
            ->layout('components.layouts.app', ['title' => 'Clients']);
    }
}
