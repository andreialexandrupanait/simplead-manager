<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\SeoAudit;
use App\Models\Site;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SeoTechnical extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function latestAudit(): ?SeoAudit
    {
        return $this->site->latestSeoAudit;
    }

    #[Computed]
    public function robotsTxt(): ?array
    {
        return $this->latestAudit?->data['robots_txt'] ?? null;
    }

    #[Computed]
    public function sitemaps(): ?array
    {
        $fromData = $this->latestAudit?->data['sitemaps'] ?? null;

        if ($fromData !== null) {
            return $fromData;
        }

        $gsc = $this->latestAudit?->data['gsc_sitemaps'] ?? null;

        return $gsc;
    }

    #[Computed]
    public function structuredData(): array
    {
        return $this->latestAudit?->data['structured_data'] ?? [];
    }

    #[Computed]
    public function redirects(): ?array
    {
        return $this->latestAudit?->data['redirects'] ?? null;
    }

    #[Computed]
    public function brokenLinks(): array
    {
        return $this->latestAudit?->data['broken_links'] ?? [];
    }

    #[Computed]
    public function searchVisibility(): ?array
    {
        return $this->latestAudit?->data['search_visibility'] ?? null;
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-technical')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — SEO',
            ]);
    }
}
