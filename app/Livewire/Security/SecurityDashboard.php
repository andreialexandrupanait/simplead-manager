<?php

declare(strict_types=1);

namespace App\Livewire\Security;

use App\Livewire\Traits\WithSorting;
use App\Models\SecurityCommand;
use App\Models\SecurityPreset;
use App\Models\Site;
use App\Services\SecuritySettingsService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class SecurityDashboard extends Component
{
    use WithPagination, WithSorting;

    protected string $defaultSortBy = 'security_hardening_score';

    protected string $defaultSortDir = 'desc';

    public string $scoreFilter = '';

    public string $search = '';

    public ?int $bulkPresetId = null;

    private function scopedSiteQuery(): Builder
    {
        return Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()));
    }

    #[Computed]
    public function avgScore(): ?float
    {
        $avg = $this->scopedSiteQuery()
            ->whereNotNull('security_hardening_score')
            ->avg('security_hardening_score');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    #[Computed]
    public function atRiskSites(): int
    {
        return $this->scopedSiteQuery()
            ->whereNotNull('security_hardening_score')
            ->where('security_hardening_score', '<', 50)
            ->count();
    }

    #[Computed]
    public function pendingCommandsCount(): int
    {
        $siteIds = $this->scopedSiteQuery()->pluck('id');

        return SecurityCommand::whereIn('site_id', $siteIds)
            ->where('status', 'pending')
            ->count();
    }

    #[Computed]
    public function presets()
    {
        return SecurityPreset::orderBy('name')->get();
    }

    #[Computed]
    public function sites()
    {
        $query = $this->scopedSiteQuery()
            ->select('sites.*')
            ->withCount([
                'securitySettings as enabled_settings_count' => function ($q) {
                    $q->where('is_enabled', true);
                },
                'securityCommands as pending_commands_count' => function ($q) {
                    $q->where('status', 'pending');
                },
            ])
            ->addSelect([
                'last_security_sync' => \App\Models\SecuritySetting::select('applied_at')
                    ->whereColumn('site_id', 'sites.id')
                    ->whereNotNull('applied_at')
                    ->orderByDesc('applied_at')
                    ->limit(1),
            ])
            ->orderBy(
                in_array($this->sortBy, ['name', 'security_hardening_score']) ? $this->sortBy : 'security_hardening_score',
                $this->sortDir
            );

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('url', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->scoreFilter === 'at_risk') {
            $query->where(function ($q) {
                $q->where('security_hardening_score', '<', 50)
                    ->orWhereNull('security_hardening_score');
            });
        } elseif ($this->scoreFilter === 'good') {
            $query->where('security_hardening_score', '>=', 50)
                ->where('security_hardening_score', '<', 80);
        } elseif ($this->scoreFilter === 'excellent') {
            $query->where('security_hardening_score', '>=', 80);
        }

        return $query->paginate(50);
    }

    public function updatedSearch(): void
    {
        $this->search = substr(trim($this->search), 0, 100);
        unset($this->sites);
    }

    public function updatedScoreFilter(): void
    {
        unset($this->sites);
    }

    public function bulkApplyPreset(array $ids): void
    {
        if (empty($ids) || ! $this->bulkPresetId) {
            return;
        }

        $preset = SecurityPreset::find($this->bulkPresetId);
        if (! $preset) {
            return;
        }

        $sites = $this->scopedSiteQuery()->whereIn('id', $ids)->get();
        app(SecuritySettingsService::class)->applyPreset($preset, $sites);

        $this->bulkPresetId = null;
        unset($this->sites, $this->pendingCommandsCount);

        session()->flash('dash-success', "Preset '{$preset->name}' applied to {$sites->count()} site(s).");
    }

    public function render()
    {
        return view('livewire.security.security-dashboard')
            ->layout('components.layouts.app', [
                'title' => 'Security Dashboard',
            ]);
    }
}
