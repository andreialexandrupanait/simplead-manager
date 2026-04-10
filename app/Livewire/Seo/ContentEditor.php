<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Enums\SeoContentStatus;
use App\Jobs\GenerateSeoContent;
use App\Jobs\PublishSeoContent;
use App\Livewire\Traits\WithJobTracking;
use App\Models\SeoContent;
use App\Models\Site;
use App\Services\SeoContentAiService;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class ContentEditor extends Component
{
    use WithJobTracking;

    public ?SeoContent $seoContent = null;

    public ?int $siteId = null;

    public string $title = '';

    public string $targetKeyword = '';

    public string $secondaryKeywords = '';

    public string $brief = '';

    public string $content = '';

    public string $metaDescription = '';

    public string $tone = 'professional';

    public string $persona = 'noi';

    public string $targetAudience = '';

    public int $targetWordCount = 1000;

    public string $aiProvider = '';

    public string $aiModel = '';

    protected function jobTrackingKeys(): array
    {
        return ['generate' => $this->seoContent ? 'seo-content-'.$this->seoContent->id : ''];
    }

    public function mount(?SeoContent $seoContent = null): void
    {
        if ($seoContent?->id) {
            $this->seoContent = $seoContent;
            $this->siteId = $seoContent->site_id;
            $this->title = $seoContent->title;
            $this->targetKeyword = $seoContent->target_keyword ?? '';
            $this->secondaryKeywords = implode(', ', $seoContent->secondary_keywords ?? []);
            $this->brief = $seoContent->brief ?? '';
            $this->content = $seoContent->content ?? '';
            $this->metaDescription = $seoContent->meta_description ?? '';
            $this->tone = $seoContent->tone ?? 'professional';
            $this->persona = $seoContent->persona ?? 'noi';
            $this->targetAudience = $seoContent->target_audience ?? '';
            $this->targetWordCount = $seoContent->target_word_count ?? 1000;
            $this->aiProvider = $seoContent->ai_provider ?? '';
            $this->aiModel = $seoContent->ai_model ?? '';
        }

        // Set default provider if none selected
        if (! $this->aiProvider) {
            $configured = SeoContentAiService::configuredProviders();
            $this->aiProvider = array_key_first($configured) ?? '';
        }

        // Set default model if none selected
        if (! $this->aiModel && $this->aiProvider) {
            $this->aiModel = $this->getDefaultModelForProvider($this->aiProvider);
        }
    }

    #[Computed]
    public function configuredProviders(): array
    {
        return SeoContentAiService::configuredProviders();
    }

    public function updatedAiProvider(): void
    {
        $this->aiModel = $this->getDefaultModelForProvider($this->aiProvider);
    }

    private function getDefaultModelForProvider(string $provider): string
    {
        $providers = SeoContentAiService::availableProviders();

        return array_key_first($providers[$provider]['models'] ?? []) ?? '';
    }

    #[Computed]
    public function sites()
    {
        return Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get(['id', 'name', 'url']);
    }

    #[Computed]
    public function seoScore(): array
    {
        if (! $this->seoContent) {
            return [];
        }

        return app(SeoContentAiService::class)->calculateSeoScore(
            $this->seoContent,
            $this->content ?: null,
            $this->metaDescription ?: null,
        );
    }

    #[Computed]
    public function revisions()
    {
        if (! $this->seoContent) {
            return collect();
        }

        return $this->seoContent->revisions()->latest()->limit(10)->get();
    }

    public function saveDraft(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'siteId' => 'nullable|exists:sites,id',
        ]);

        $data = $this->getContentData();

        if ($this->seoContent) {
            $this->seoContent->update($data);

            // Save manual revision if content changed
            if ($this->content && $this->seoContent->wasChanged('content')) {
                $this->seoContent->revisions()->create([
                    'content' => $this->content,
                    'meta_description' => $this->metaDescription,
                    'source' => 'manual',
                ]);
            }
        } else {
            $data['user_id'] = auth()->id();
            $data['status'] = SeoContentStatus::Draft;
            $this->seoContent = SeoContent::create($data);
        }

        unset($this->seoScore, $this->revisions);
        session()->flash('success', __('Draft saved.'));
    }

    public function generateArticle(): void
    {
        $this->saveDraft();

        if (! $this->seoContent) {
            return;
        }

        $this->dispatchTrackedJob('generate', new GenerateSeoContent($this->seoContent), 'Generating article...');
    }

    public function onJobFinished(string $jobName, array $data): void
    {
        if ($this->seoContent) {
            $this->seoContent->refresh();
            $this->content = $this->seoContent->content ?? '';
            $this->metaDescription = $this->seoContent->meta_description ?? '';
        }
        unset($this->seoScore, $this->revisions);
    }

    public function publishToWordPress(): void
    {
        if (! $this->seoContent || ! $this->seoContent->site_id) {
            session()->flash('error', __('Select a site before publishing.'));

            return;
        }

        $this->saveDraft();
        dispatch(new PublishSeoContent($this->seoContent));
        session()->flash('success', __('Publishing to WordPress...'));
    }

    public function restoreRevision(int $revisionId): void
    {
        if (! $this->seoContent) {
            return;
        }

        $revision = $this->seoContent->revisions()->findOrFail($revisionId);
        $this->content = $revision->content;
        $this->metaDescription = $revision->meta_description ?? $this->metaDescription;

        $this->seoContent->update([
            'content' => $this->content,
            'meta_description' => $this->metaDescription,
        ]);

        unset($this->seoScore);
        session()->flash('success', __('Revision restored.'));
    }

    public function schedulePublish(string $datetime): void
    {
        if (! $this->seoContent) {
            return;
        }

        $this->saveDraft();
        $this->seoContent->update([
            'status' => SeoContentStatus::Scheduled,
            'scheduled_at' => $datetime,
        ]);

        session()->flash('success', __('Article scheduled.'));
    }

    private function getContentData(): array
    {
        $secondary = array_filter(array_map('trim', explode(',', $this->secondaryKeywords)));

        return [
            'site_id' => $this->siteId,
            'title' => $this->title,
            'slug' => Str::slug($this->title),
            'target_keyword' => $this->targetKeyword,
            'secondary_keywords' => $secondary,
            'brief' => $this->brief,
            'content' => $this->content,
            'meta_description' => $this->metaDescription,
            'tone' => $this->tone,
            'persona' => $this->persona,
            'ai_provider' => $this->aiProvider,
            'ai_model' => $this->aiModel,
            'target_audience' => $this->targetAudience,
            'target_word_count' => $this->targetWordCount,
        ];
    }

    public function render()
    {
        return view('livewire.seo.content-editor')
            ->layout('components.layouts.app', [
                'title' => $this->seoContent ? 'Edit Article' : 'New Article',
            ]);
    }
}
