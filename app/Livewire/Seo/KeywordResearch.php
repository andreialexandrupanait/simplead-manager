<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Jobs\RunKeywordResearch as RunKeywordResearchJob;
use App\Livewire\Traits\WithJobTracking;
use App\Models\KeywordResearchResult;
use App\Models\Site;
use App\Models\TrackedKeyword;
use App\Services\KeywordTrackingService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class KeywordResearch extends Component
{
    use WithJobTracking;

    #[Validate('required|string|min:2|max:100')]
    public string $seedKeyword = '';

    public string $language = 'ro';

    public string $country = 'ro';

    public ?int $siteId = null;

    public ?int $activeResultId = null;

    protected function jobTrackingKeys(): array
    {
        return ['research' => $this->activeResultId ? 'keyword-research-'.$this->activeResultId : ''];
    }

    #[Computed]
    public function sites()
    {
        return Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function recentResults()
    {
        return KeywordResearchResult::where('user_id', auth()->id())
            ->latest()
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function activeResult(): ?KeywordResearchResult
    {
        if (! $this->activeResultId) {
            return null;
        }

        return KeywordResearchResult::where('user_id', auth()->id())
            ->find($this->activeResultId);
    }

    public function startResearch(): void
    {
        $this->validate();

        $result = KeywordResearchResult::create([
            'user_id' => auth()->id(),
            'site_id' => $this->siteId,
            'seed_keyword' => $this->seedKeyword,
            'language' => $this->language,
            'country' => $this->country,
        ]);

        $this->activeResultId = $result->id;

        $this->dispatchTrackedJob('research', new RunKeywordResearchJob($result), 'Starting keyword research...');
    }

    public function onJobFinished(string $jobName, array $data): void
    {
        unset($this->activeResult, $this->recentResults);
    }

    public function viewResult(int $id): void
    {
        $this->activeResultId = $id;
        unset($this->activeResult);
    }

    public function addToTracking(string $keyword): void
    {
        if (! $this->siteId) {
            session()->flash('error', __('Select a site to track keywords.'));

            return;
        }

        $site = Site::findOrFail($this->siteId);
        app(KeywordTrackingService::class)->addKeyword($site, $keyword);

        session()->flash('success', __('Keyword added to tracking: ').$keyword);
    }

    public function deleteResult(int $id): void
    {
        KeywordResearchResult::where('user_id', auth()->id())->findOrFail($id)->delete();

        if ($this->activeResultId === $id) {
            $this->activeResultId = null;
            unset($this->activeResult);
        }
        unset($this->recentResults);
    }

    public function render()
    {
        return view('livewire.seo.keyword-research')
            ->layout('components.layouts.app', ['title' => 'Keyword Research']);
    }
}
