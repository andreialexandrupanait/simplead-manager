<?php

declare(strict_types=1);

namespace App\Livewire\ErrorLogs;

use App\Livewire\Traits\WithVisibleSites;
use App\Models\PhpErrorLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ErrorLogsOverview extends Component
{
    use WithPagination, WithVisibleSites;

    public string $filter = 'all';

    public string $search = '';

    #[Url]
    public ?int $site = null;

    /**
     * Base query restricted to error logs for sites the current user may access
     * (admins see all). Without this, any authenticated user could read and
     * resolve PHP error logs — file paths, SQL fragments — for every client.
     */
    protected function accessibleErrorLogs(): \Illuminate\Database\Eloquent\Builder
    {
        $query = PhpErrorLog::query();

        $ids = $this->visibleSiteIds();
        if ($ids !== null) {
            $query->whereIn('site_id', $ids);
        }

        return $query;
    }

    #[Computed]
    public function stats(): array
    {
        $base = $this->accessibleErrorLogs()->unresolved();

        return [
            'total' => (clone $base)->count(),
            'fatal' => (clone $base)->fatal()->count(),
            'warning' => (clone $base)->where('level', 'warning')->count(),
            'sites' => (clone $base)->distinct('site_id')->count('site_id'),
        ];
    }

    public function resolve(int $id): void
    {
        $log = PhpErrorLog::with('site')->findOrFail($id);

        // Resolving hides a fatal from the unresolved stats + issues feed — a
        // write. Block Viewers (P1-04) in addition to the cross-tenant check.
        $user = auth()->user();
        abort_if((bool) $user?->isViewer(), 403, 'Viewers cannot resolve error logs.');
        abort_unless($log->site && $user?->canAccessSite($log->site), 403);

        $log->update(['is_resolved' => true]);
        unset($this->stats);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $errors = $this->accessibleErrorLogs()->with('site')
            ->when($this->site, fn ($q) => $q->where('site_id', $this->site))
            ->when($this->filter === 'fatal', fn ($q) => $q->fatal())
            ->when($this->filter === 'warning', fn ($q) => $q->where('level', 'warning'))
            ->when($this->filter === 'unresolved', fn ($q) => $q->unresolved())
            ->when($this->filter === 'resolved', fn ($q) => $q->where('is_resolved', true))
            ->when($this->search, function ($q) {
                $s = '%'.str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $this->search).'%';
                $q->where(function ($sq) use ($s) {
                    $sq->where('message', 'ilike', $s)
                        ->orWhereHas('site', fn ($site) => $site->where('name', 'ilike', $s));
                });
            })
            ->join('sites', 'php_error_logs.site_id', '=', 'sites.id')
            ->orderBy('sites.sort_order')
            ->orderByDesc('php_error_logs.last_seen_at')
            ->select('php_error_logs.*')
            ->paginate(50);

        return view('livewire.error-logs.error-logs-overview', [
            'errors' => $errors,
        ])->layout('components.layouts.app', ['title' => 'Error Logs']);
    }
}
