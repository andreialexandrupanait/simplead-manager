<?php

namespace App\Livewire\Sites;

use App\Livewire\Traits\WithTableFilters;
use App\Models\Site;
use Livewire\Component;

class SitesList extends Component
{
    use WithTableFilters;

    protected $listeners = ['site-deleted' => '$refresh'];

    public function render()
    {
        $user = auth()->user();

        $sites = Site::query()
            ->when(!$user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('url', 'like', "%{$this->search}%");
            }))
            ->when($this->filter !== 'all', function ($q) {
                return match($this->filter) {
                    'healthy' => $q->where('health_score', '>=', 90)->where('is_up', true),
                    'warning' => $q->where('health_score', '>=', 70)->where('health_score', '<', 90)->where('is_up', true),
                    'critical' => $q->where(function ($q) {
                        $q->where('health_score', '<', 70)->orWhere('is_up', false);
                    }),
                    default => $q,
                };
            })
            ->with('client', 'uptimeMonitor', 'backupConfig', 'performanceMonitor', 'siteStatus', 'sslCertificate', 'analyticsConnection', 'searchConsoleConnection')
            ->withCount(['reportSchedules', 'siteUsers', 'sitePlugins'])
            ->latest()
            ->paginate(16);

        return view('livewire.sites.sites-list', compact('sites'))
            ->layout('components.layouts.app', ['title' => 'Sites']);
    }
}
