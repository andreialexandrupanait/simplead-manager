<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\SeoAlertRule;
use App\Models\Site;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

class SeoAlerts extends Component
{
    use WithPagination;

    public string $search = '';

    public string $typeFilter = '';

    public string $activeFilter = '';

    public ?int $siteFilter = null;

    // Create form fields
    #[Validate('required|exists:sites,id')]
    public ?int $newSiteId = null;

    #[Validate('required|in:position_drop,traffic_drop,indexing_change,score_drop,page_error,cwv_regression')]
    public string $newRuleType = '';

    #[Validate('required|boolean')]
    public bool $newIsActive = true;

    #[Validate('required|integer|min:1|max:10080')]
    public int $newCooldownMinutes = 60;

    #[Computed]
    public function sites()
    {
        return Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function rules()
    {
        $query = SeoAlertRule::with('site')
            ->whereHas('site', fn ($q) => $q->when(
                ! auth()->user()->isAdmin(),
                fn ($q2) => $q2->where('user_id', auth()->id())
            ));

        if ($this->typeFilter) {
            $query->where('rule_type', $this->typeFilter);
        }

        if ($this->siteFilter) {
            $query->where('site_id', $this->siteFilter);
        }

        if ($this->activeFilter !== '') {
            $query->where('is_active', $this->activeFilter === '1');
        }

        if ($this->search) {
            $search = $this->search;
            $query->whereHas('site', fn ($q) => $q->where('name', 'ilike', "%{$search}%"));
        }

        return $query->latest()->paginate(25);
    }

    public function updatedSearch(): void
    {
        $this->search = substr(trim($this->search), 0, 100);
        $this->resetPage();
        unset($this->rules);
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
        unset($this->rules);
    }

    public function updatedSiteFilter(): void
    {
        $this->resetPage();
        unset($this->rules);
    }

    public function updatedActiveFilter(): void
    {
        $this->resetPage();
        unset($this->rules);
    }

    public function updatedNewRuleType(): void
    {
        // Reset cooldown to a sensible default when type changes
        $this->newCooldownMinutes = 60;
    }

    public function createRule(): void
    {
        $this->validate();

        $site = Site::findOrFail($this->newSiteId);

        if (! auth()->user()->isAdmin() && $site->user_id !== auth()->id()) {
            $this->addError('newSiteId', __('You do not have access to this site.'));

            return;
        }

        SeoAlertRule::create([
            'site_id' => $this->newSiteId,
            'rule_type' => $this->newRuleType,
            'threshold' => SeoAlertRule::defaultThreshold($this->newRuleType),
            'is_active' => $this->newIsActive,
            'cooldown_minutes' => $this->newCooldownMinutes,
        ]);

        $this->reset('newSiteId', 'newRuleType', 'newIsActive', 'newCooldownMinutes');
        $this->newIsActive = true;
        $this->newCooldownMinutes = 60;

        unset($this->rules);

        $this->dispatch('close-modal-create-rule');
        session()->flash('success', __('Alert rule created.'));
    }

    public function toggleActive(int $id): void
    {
        $rule = SeoAlertRule::with('site')->findOrFail($id);

        if (! auth()->user()->isAdmin() && $rule->site?->user_id !== auth()->id()) {
            session()->flash('error', __('You do not have access to this rule.'));

            return;
        }

        $rule->update(['is_active' => ! $rule->is_active]);

        unset($this->rules);

        session()->flash('success', $rule->is_active
            ? __('Alert rule deactivated.')
            : __('Alert rule activated.')
        );
    }

    public function deleteRule(int $id): void
    {
        $rule = SeoAlertRule::with('site')->findOrFail($id);

        if (! auth()->user()->isAdmin() && $rule->site?->user_id !== auth()->id()) {
            session()->flash('error', __('You do not have access to this rule.'));

            return;
        }

        $rule->delete();
        unset($this->rules);

        session()->flash('success', __('Alert rule deleted.'));
    }

    public function render()
    {
        return view('livewire.seo.seo-alerts')
            ->layout('components.layouts.app', ['title' => 'SEO Alerts']);
    }
}
