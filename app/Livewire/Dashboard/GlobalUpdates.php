<?php

namespace App\Livewire\Dashboard;

use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class GlobalUpdates extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $typeFilter = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'core' => Site::whereNotNull('core_update_version')->count(),
            'plugins' => SitePlugin::where('has_update', true)->count(),
            'themes' => SiteTheme::where('has_update', true)->count(),
        ];
    }

    #[Computed]
    public function sites()
    {
        $query = Site::query()
            ->with(['sitePlugins' => fn ($q) => $q->where('has_update', true), 'siteThemes' => fn ($q) => $q->where('has_update', true)])
            ->where(function ($q) {
                $q->whereNotNull('core_update_version')
                    ->orWhereHas('sitePlugins', fn ($sq) => $sq->where('has_update', true))
                    ->orWhereHas('siteThemes', fn ($sq) => $sq->where('has_update', true));
            });

        if ($this->typeFilter === 'core') {
            $query->whereNotNull('core_update_version');
        } elseif ($this->typeFilter === 'plugins') {
            $query->whereHas('sitePlugins', fn ($sq) => $sq->where('has_update', true));
        } elseif ($this->typeFilter === 'themes') {
            $query->whereHas('siteThemes', fn ($sq) => $sq->where('has_update', true));
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('url', 'like', "%{$this->search}%")
                    ->orWhereHas('sitePlugins', fn ($sq) => $sq->where('has_update', true)->where('name', 'like', "%{$this->search}%"))
                    ->orWhereHas('siteThemes', fn ($sq) => $sq->where('has_update', true)->where('name', 'like', "%{$this->search}%"));
            });
        }

        return $query->orderBy('name')->paginate(20);
    }

    public function render()
    {
        return view('livewire.dashboard.global-updates')
            ->layout('components.layouts.app', ['title' => 'Updates']);
    }
}
