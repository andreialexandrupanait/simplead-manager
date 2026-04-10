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
use App\Services\WordPressApiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
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

    public bool $editing = false;

    public string $corrections = '';

    public string $siteAiContext = '';

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

        // Load site AI context
        if ($this->siteId) {
            $site = Site::find($this->siteId);
            $this->siteAiContext = $site?->ai_context ?? '';
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
            'title' => 'nullable|string|max:255',
            'targetKeyword' => 'required|string|max:255',
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
            $this->title = $this->seoContent->title ?? $this->title;
            $this->content = $this->seoContent->content ?? '';
            $this->metaDescription = $this->seoContent->meta_description ?? '';
            $this->corrections = '';
        }
        unset($this->seoScore, $this->revisions);
    }

    public function toggleEditing(): void
    {
        $this->editing = ! $this->editing;
    }

    public function applyCorrections(): void
    {
        if (! $this->seoContent || ! $this->corrections) {
            return;
        }

        $this->saveDraft();
        $this->dispatchTrackedJob('generate', new GenerateSeoContent($this->seoContent, $this->corrections), 'Applying corrections...');
    }

    public function updatedSiteId(): void
    {
        // Load site AI context when site changes
        if ($this->siteId) {
            $site = Site::find($this->siteId);
            $this->siteAiContext = $site?->ai_context ?? '';
        } else {
            $this->siteAiContext = '';
        }
    }

    public function saveSiteContext(): void
    {
        if (! $this->siteId) {
            return;
        }

        Site::where('id', $this->siteId)->update(['ai_context' => $this->siteAiContext]);
        session()->flash('success', __('Site AI context saved.'));
    }

    public function autoDetectSiteContext(): void
    {
        if (! $this->siteId) {
            return;
        }

        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        $gathered = [];

        // 1. Fetch WP site info via connector API
        if ($site->is_connected) {
            try {
                $api = new WordPressApiService($site);
                $info = $api->getInfo();
                $gathered[] = "Site title: ".($info['site_title'] ?? $site->name);
                $gathered[] = "URL: ".($info['home_url'] ?? $site->url);
                $gathered[] = "Language: ".($info['language'] ?? 'ro_RO');

                // Fetch categories
                $categories = $api->getPostCategories();
                if (! empty($categories)) {
                    $catNames = array_column($categories, 'name');
                    $gathered[] = "Blog categories: ".implode(', ', array_slice($catNames, 0, 15));
                }
            } catch (\Throwable $e) {
                $gathered[] = "Site: {$site->name} ({$site->url})";
            }
        } else {
            $gathered[] = "Site: {$site->name} ({$site->url})";
        }

        // 2. Scrape homepage for text content
        try {
            $response = Http::timeout(15)->get($site->url);
            if ($response->successful()) {
                $html = $response->body();

                // Extract meta description
                if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/i', $html, $m)) {
                    $gathered[] = "Site meta description: ".$m[1];
                }

                // Extract OG description
                if (preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\'](.*?)["\']/i', $html, $m)) {
                    $gathered[] = "OG description: ".$m[1];
                }

                // Extract visible text (strip scripts, styles, tags)
                $clean = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
                $clean = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $clean);
                $clean = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $clean);
                $clean = preg_replace('/<footer[^>]*>.*?<\/footer>/si', '', $clean);
                $text = trim(preg_replace('/\s+/', ' ', strip_tags($clean)));
                $text = mb_substr($text, 0, 2000);
                $gathered[] = "Homepage text excerpt: {$text}";
            }
        } catch (\Throwable) {
            // skip
        }

        if (empty($gathered)) {
            session()->flash('error', __('Could not gather site information.'));

            return;
        }

        // 3. Use AI to generate brand context summary
        $configured = SeoContentAiService::configuredProviders();
        $provider = $this->aiProvider ?: array_key_first($configured) ?? '';
        if (! $provider) {
            // No AI available, just dump raw info
            $this->siteAiContext = implode("\n", $gathered);
            session()->flash('success', __('Site info gathered. Review and save.'));

            return;
        }

        $model = $this->aiModel ?: ($this->getDefaultModelForProvider($provider));
        $service = app(SeoContentAiService::class);

        // Use reflection to call the provider directly
        $system = "Ești un analist de brand. Pe baza informațiilor de mai jos despre un website, generează un rezumat concis (max 300 cuvinte) în limba română care descrie:\n- Ce face compania/site-ul\n- Ce produse/servicii oferă\n- Care e publicul țintă\n- Ce ton de comunicare folosesc\n- Orice detalii relevante pentru un copywriter care va scrie articole pentru acest site\n\nScrie DOAR rezumatul, fără introducere sau explicații.";

        $userMsg = implode("\n", $gathered);

        $result = $service->callProvider($provider, $model, $system, $userMsg, 1024);

        if ($result) {
            $this->siteAiContext = trim($result);
            session()->flash('success', __('Site context auto-generated. Review and save.'));
        } else {
            $this->siteAiContext = implode("\n", $gathered);
            session()->flash('success', __('AI unavailable. Raw site info gathered — edit as needed.'));
        }
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

        $title = $this->title ?: $this->targetKeyword;

        return [
            'site_id' => $this->siteId,
            'title' => $title,
            'slug' => Str::slug($title),
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
