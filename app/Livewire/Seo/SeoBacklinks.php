<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Livewire\Traits\WithSorting;
use App\Models\Backlink;
use App\Models\BacklinkSnapshot;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SeoBacklinks extends Component
{
    use WithPagination, WithSorting;

    protected string $defaultSortBy = 'total_backlinks';

    protected string $defaultSortDir = 'desc';

    #[Url]
    public int|string $siteId = '';

    #[Computed]
    public function stats(): array
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30)->toDateString();

        $baseQuery = Backlink::query()
            ->when(! auth()->user()->isAdmin(), fn (Builder $q) => $q->whereHas(
                'site',
                fn (Builder $sq) => $sq->where('user_id', auth()->id())
            ))
            ->when($this->siteId !== '', fn (Builder $q) => $q->where('site_id', $this->siteId));

        $totalBacklinks = (clone $baseQuery)->active()->count();

        $referringDomains = (clone $baseQuery)
            ->active()
            ->distinct('source_domain')
            ->count('source_domain');

        $newLast30d = (clone $baseQuery)
            ->active()
            ->where('first_seen_at', '>=', $thirtyDaysAgo)
            ->count();

        $lostLast30d = (clone $baseQuery)
            ->lost()
            ->where('lost_at', '>=', $thirtyDaysAgo)
            ->count();

        return [
            'total_backlinks' => $totalBacklinks,
            'referring_domains' => $referringDomains,
            'new_last_30d' => $newLast30d,
            'lost_last_30d' => $lostLast30d,
        ];
    }

    #[Computed]
    public function sites()
    {
        $allowedSortColumns = ['total_backlinks', 'referring_domains'];
        $sort = in_array($this->sortBy, $allowedSortColumns, true) ? $this->sortBy : 'total_backlinks';

        $query = Site::query()
            ->when(! auth()->user()->isAdmin(), fn (Builder $q) => $q->where('user_id', auth()->id()))
            ->when($this->siteId !== '', fn (Builder $q) => $q->where('id', $this->siteId))
            ->with(['latestBacklinkSnapshot'])
            ->whereHas('backlinkSnapshots');

        return $query
            ->orderByDesc(
                BacklinkSnapshot::select($sort)
                    ->whereColumn('site_id', 'sites.id')
                    ->latest('date')
                    ->limit(1)
            )
            ->paginate(25);
    }

    #[Computed]
    public function siteOptions()
    {
        return Site::query()
            ->when(! auth()->user()->isAdmin(), fn (Builder $q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function updatedSiteId(): void
    {
        $this->resetPage();
        unset($this->sites, $this->stats);
    }

    public function render()
    {
        return view('livewire.seo.seo-backlinks')
            ->layout('components.layouts.app', ['title' => 'Backlinks']);
    }
}
