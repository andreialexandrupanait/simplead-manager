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
            $this->dispatch('notify', type: 'success', message: 'Client deleted successfully.');
        }

        $this->deletingId = null;
        $this->dispatch('close-modal-delete-client');
    }

    public function changeStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'inactive', 'archived'])) return;

        Client::where('id', $id)->update(['status' => $status]);
        $this->dispatch('notify', type: 'success', message: 'Client status updated.');
    }

    #[Computed]
    public function statusCounts(): array
    {
        $counts = Client::query()
            ->selectRaw("count(*) as total")
            ->selectRaw("count(*) filter (where status = 'active') as active")
            ->selectRaw("count(*) filter (where status = 'inactive') as inactive")
            ->selectRaw("count(*) filter (where status = 'archived') as archived")
            ->first();

        return [
            'all' => (int) $counts->total,
            'active' => (int) $counts->active,
            'inactive' => (int) $counts->inactive,
            'archived' => (int) $counts->archived,
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
