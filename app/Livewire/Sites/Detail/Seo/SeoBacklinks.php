<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\BacklinkService;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class SeoBacklinks extends Component
{
    use WithFileUploads, WithSiteAuthorization;

    public Site $site;

    #[Validate('required|file|mimes:csv,txt|max:10240')]
    public $csvFile = null;

    public bool $showImportForm = false;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function stats(): array
    {
        return app(BacklinkService::class)->getStats($this->site);
    }

    #[Computed]
    public function topLinkedPages(): array
    {
        return app(BacklinkService::class)->getTopLinkedPages($this->site, 20);
    }

    #[Computed]
    public function anchorDistribution(): array
    {
        return app(BacklinkService::class)->getAnchorDistribution($this->site, 30);
    }

    public function importCsv(): void
    {
        $this->validate();

        $path = $this->csvFile->getRealPath();
        $imported = app(BacklinkService::class)->importFromCsv($this->site, $path);

        $this->csvFile = null;
        $this->showImportForm = false;

        unset($this->stats, $this->topLinkedPages, $this->anchorDistribution);

        Session::flash('success', __(':count backlinks imported successfully.', ['count' => $imported]));
    }

    public function toggleImportForm(): void
    {
        $this->showImportForm = ! $this->showImportForm;

        if (! $this->showImportForm) {
            $this->csvFile = null;
        }
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-backlinks')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Backlinks',
            ]);
    }
}
