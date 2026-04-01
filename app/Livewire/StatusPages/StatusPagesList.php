<?php

declare(strict_types=1);

namespace App\Livewire\StatusPages;

use App\Models\StatusPage;
use Livewire\Component;
use Livewire\WithPagination;

class StatusPagesList extends Component
{
    use WithPagination;

    public ?int $deletingId = null;

    public function render()
    {
        $statusPages = StatusPage::withCount(['statusPageSites', 'incidents' => function ($q) {
            $q->active();
        }])
            ->orderByDesc('created_at')
            ->simplePaginate(15);

        return view('livewire.status-pages.status-pages-list', [
            'statusPages' => $statusPages,
        ])->layout('components.layouts.app', ['title' => 'Status Pages']);
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->dispatch('open-modal-delete-status-page');
    }

    public function deleteStatusPage(): void
    {
        if ($this->deletingId) {
            StatusPage::find($this->deletingId)?->delete();
        }

        $this->dispatch('close-modal-delete-status-page');
        $this->deletingId = null;
        session()->flash('success', 'Status page deleted.');
        $this->resetPage();
    }
}
