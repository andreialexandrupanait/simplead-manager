<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Services\RedirectSyncService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteRedirects extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public string $sourcePath = '';

    public string $targetUrl = '';

    public int $statusCode = 301;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function redirects()
    {
        return $this->site->redirects()->orderByDesc('created_at')->get();
    }

    #[Computed]
    public function brokenLinks()
    {
        // Suggest fixes: distinct broken target URLs from the latest SEO audit.
        /** @var \App\Models\SeoAudit|null $audit */
        $audit = $this->site->seoAudits()->latest('scanned_at')->first();
        if (! $audit) {
            return collect();
        }

        return $audit->links()->where('is_broken', true)
            ->orderBy('target_url')
            ->get()
            ->unique('target_url')
            ->take(20)
            ->values();
    }

    public function prefillFromBroken(string $url): void
    {
        $this->sourcePath = SiteRedirect::normalizePath($url);
        $this->targetUrl = rtrim($this->site->url, '/').'/';
    }

    public function addRedirect(): void
    {
        $this->authorizeSiteModification($this->site);

        $this->validate([
            'sourcePath' => 'required|string|max:2000',
            'targetUrl' => 'required|url|max:2000',
            'statusCode' => 'required|in:301,302',
        ]);

        $source = SiteRedirect::normalizePath($this->sourcePath);

        $this->site->redirects()->updateOrCreate(
            ['source_path' => $source],
            ['target_url' => $this->targetUrl, 'status_code' => $this->statusCode, 'is_active' => true],
        );

        $this->reset('sourcePath', 'targetUrl');
        $this->statusCode = 301;
        unset($this->redirects);
        $this->sync();
    }

    public function toggleRedirect(int $id): void
    {
        $this->authorizeSiteModification($this->site);

        /** @var SiteRedirect $redirect */
        $redirect = $this->site->redirects()->whereKey($id)->firstOrFail();
        $redirect->update(['is_active' => ! $redirect->is_active]);
        unset($this->redirects);
        $this->sync();
    }

    public function deleteRedirect(int $id): void
    {
        $this->authorizeSiteModification($this->site);

        $this->site->redirects()->whereKey($id)->delete();
        unset($this->redirects);
        $this->sync();
    }

    private function sync(): void
    {
        try {
            app(RedirectSyncService::class)->push($this->site);
            $this->dispatch('notify', type: 'success', message: 'Redirects saved and pushed to the site.');
        } catch (\Throwable $e) {
            Log::warning("Failed to push redirects for site {$this->site->id}: {$e->getMessage()}");
            $this->dispatch('notify', type: 'error', message: 'Saved, but pushing to the site failed: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.sites.detail.site-redirects')
            ->layout('components.layouts.app', ['title' => 'Redirects']);
    }
}
