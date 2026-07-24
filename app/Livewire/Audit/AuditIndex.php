<?php

declare(strict_types=1);

namespace App\Livewire\Audit;

use App\Enums\AuditStatus;
use App\Models\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Faza D: the audit module index — every audit run (site or prospect target) with
 * its lifecycle status, latest crawl run, and a link into the show/editor page.
 */
class AuditIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Audit>
     */
    public function audits(): LengthAwarePaginator
    {
        return Audit::query()
            ->with(['site:id,name,url', 'prospect:id,name,url', 'latestRun'])
            ->when($this->statusFilter() !== null, fn ($q) => $q->where('status', $this->statusFilter()))
            ->latest('id')
            ->paginate(20);
    }

    private function statusFilter(): ?AuditStatus
    {
        return AuditStatus::tryFrom($this->status);
    }

    public function render(): View
    {
        return view('livewire.audit.audit-index', [
            'audits' => $this->audits(),
            'statuses' => AuditStatus::cases(),
        ])->layout('components.layouts.app', ['title' => __('Audits')]);
    }
}
