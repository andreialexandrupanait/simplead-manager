<?php

declare(strict_types=1);

namespace App\Livewire\Security;

use App\Livewire\Traits\WithSorting;
use App\Models\SecurityPreset;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\SecuritySettingsService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class SecurityDashboard extends Component
{
    use WithPagination, WithSorting;

    protected string $defaultSortBy = 'sort_order';

    protected string $defaultSortDir = 'asc';

    public string $scoreFilter = '';

    public string $search = '';

    public ?int $bulkPresetId = null;

    private function scopedSiteQuery(): Builder
    {
        return Site::query()->visibleTo(auth()->user());
    }

    /**
     * Single definition of "at risk" — score below 50 OR never configured.
     * Used by BOTH the stat tile and the at_risk filter so they cannot drift
     * (they used to disagree: the tile ignored unconfigured sites).
     */
    private function applyAtRisk(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('security_hardening_score', '<', 50)
                ->orWhereNull('security_hardening_score');
        });
    }

    private function applyFailed(Builder $query): Builder
    {
        return $query->whereHas('securitySettings', fn ($q) => $q->whereNotNull('failed_at'));
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
        return $this->applyAtRisk($this->scopedSiteQuery())->count();
    }

    #[Computed]
    public function failedSettingsCount(): int
    {
        $siteIds = $this->scopedSiteQuery()->pluck('id');

        return SecuritySetting::whereIn('site_id', $siteIds)
            ->whereNotNull('failed_at')
            ->count();
    }

    /** Sites with at least one failed setting — the "Failed (N)" tab label. */
    #[Computed]
    public function failedSitesCount(): int
    {
        return $this->applyFailed($this->scopedSiteQuery())->count();
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
                'securitySettings as failed_settings_count' => function ($q) {
                    $q->whereNotNull('failed_at');
                },
            ])
            ->addSelect([
                'last_security_sync' => SecuritySetting::select('applied_at')
                    ->whereColumn('site_id', 'sites.id')
                    ->whereNotNull('applied_at')
                    ->orderByDesc('applied_at')
                    ->limit(1),
            ])
            ->when($this->sortBy !== 'sort_order', function ($q) {
                if ($this->sortBy === 'last_security_sync') {
                    $dir = $this->sortDir === 'desc' ? 'desc' : 'asc';

                    return $q->reorder()->orderByRaw("last_security_sync {$dir} NULLS LAST");
                }

                $sortable = ['name', 'security_hardening_score', 'enabled_settings_count'];

                return $q->reorder(
                    in_array($this->sortBy, $sortable, true) ? $this->sortBy : 'sort_order',
                    $this->sortDir
                );
            });

        if ($this->search) {
            $escaped = '%'.$this->escapeLike($this->search).'%';
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'ilike', $escaped)
                    ->orWhere('url', 'ilike', $escaped);
            });
        }

        if ($this->scoreFilter === 'at_risk') {
            $this->applyAtRisk($query);
        } elseif ($this->scoreFilter === 'good') {
            $query->where('security_hardening_score', '>=', 50)
                ->where('security_hardening_score', '<', 80);
        } elseif ($this->scoreFilter === 'excellent') {
            $query->where('security_hardening_score', '>=', 80);
        } elseif ($this->scoreFilter === 'failed') {
            $this->applyFailed($query);
        }

        return $query->paginate(50);
    }

    public function updatedSearch(): void
    {
        $this->search = substr(trim($this->search), 0, 100);
        $this->resetPage();
        unset($this->sites);
    }

    public function updatedScoreFilter(): void
    {
        $this->resetPage();
        unset($this->sites);
    }

    public function bulkApplyPreset(array $ids): void
    {
        // Pushing a security preset to sites is a modification; block Viewers.
        // Cross-tenant is already prevented by scopedSiteQuery() below.
        if (auth()->user()?->isViewer()) {
            abort(403, 'Viewers cannot apply security presets.');
        }

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
        unset($this->sites, $this->failedSettingsCount, $this->failedSitesCount, $this->atRiskSites);

        session()->flash('dash-success', "Preset '{$preset->name}' applied to {$sites->count()} site(s).");
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    public function render()
    {
        return view('livewire.security.security-dashboard')
            ->layout('components.layouts.app', [
                'title' => 'Security Dashboard',
            ]);
    }
}
