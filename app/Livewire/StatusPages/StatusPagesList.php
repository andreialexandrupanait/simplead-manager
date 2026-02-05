<?php

namespace App\Livewire\StatusPages;

use App\Models\StatusPage;
use Livewire\Attributes\Computed;
use Livewire\Component;

class StatusPagesList extends Component
{
    public ?int $deletingId = null;

    #[Computed]
    public function statusPages()
    {
        return StatusPage::withCount(['statusPageSites', 'incidents' => function ($q) {
            $q->active();
        }])
            ->orderByDesc('created_at')
            ->get();
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
        unset($this->statusPages);
        session()->flash('success', 'Status page deleted.');
    }

    public function render()
    {
        return view('livewire.status-pages.status-pages-list')
            ->layout('components.layouts.app', ['title' => 'Status Pages']);
    }
}
