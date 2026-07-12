<?php

declare(strict_types=1);

namespace App\Livewire\Clients;

use App\Livewire\Traits\WithSorting;
use App\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ClientsList extends Component
{
    use AuthorizesRequests;
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
            $client = Client::findOrFail($this->deletingId);
            $this->authorize('delete', $client);
            $client->delete();
            $this->dispatch('notify', type: 'success', message: 'Client deleted successfully.');
        }

        $this->deletingId = null;
        $this->dispatch('close-modal-delete-client');
        $this->resetPage();
    }

    public function changeStatus(int $id, string $status): void
    {
        if (! in_array($status, ['active', 'inactive', 'archived'])) {
            return;
        }

        $client = Client::findOrFail($id);
        $this->authorize('update', $client);
        $client->update(['status' => $status]);
        $this->dispatch('notify', type: 'success', message: 'Client status updated.');
    }

    /**
     * Columns a user is allowed to sort by. Anything outside this allowlist is
     * ignored and falls back to a safe default so an unvalidated, user-supplied
     * `sortBy` (query string) can never reach the raw orderBy (P2-39).
     */
    private const SORTABLE_COLUMNS = ['name', 'status', 'created_at', 'sites_count'];

    private function scopedQuery()
    {
        // Canonical client-visibility scope: owned-via-site OR assigned-via-pivot
        // OR admin. Previously this only matched owned sites and ignored the
        // client_user pivot, hiding assigned clients from their users (P2-40).
        return Client::query()->visibleTo(auth()->user());
    }

    private function safeSortColumn(): string
    {
        return in_array($this->sortBy, self::SORTABLE_COLUMNS, true) ? $this->sortBy : 'name';
    }

    private function safeSortDirection(): string
    {
        return strtolower($this->sortDir) === 'desc' ? 'desc' : 'asc';
    }

    #[Computed]
    public function statusCounts(): array
    {
        $counts = $this->scopedQuery()
            ->selectRaw('count(*) as total')
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
        $clients = $this->scopedQuery()
            ->search($this->search)
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->withCount('sites')
            ->orderBy($this->safeSortColumn(), $this->safeSortDirection())
            ->paginate(12);

        return view('livewire.clients.clients-list', compact('clients'))
            ->layout('components.layouts.app', ['title' => 'Clients']);
    }
}
