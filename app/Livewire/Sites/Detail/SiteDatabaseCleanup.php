<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckDatabaseHealthJob;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\DatabaseCleanupService;
use App\Services\ModuleConfigService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteDatabaseCleanup extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    public Site $site;

    public ?array $stats = null;

    public bool $statsLoading = false;

    public bool $cleanRevisions = true;

    public bool $cleanAutoDrafts = true;

    public bool $cleanTrashPosts = true;

    public bool $cleanSpamComments = true;

    public bool $cleanTrashComments = true;

    public bool $cleanTransients = true;

    public bool $cleanOrphanedMeta = true;

    public bool $showConfirmation = false;

    protected function jobTrackingKeys(): array
    {
        return ['health' => 'db-health-'.$this->site->id];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();
    }

    #[Computed]
    public function isModuleActive(): bool
    {
        return app(ModuleConfigService::class)->isModuleActive($this->site, 'database_cleanup');
    }

    public function activateModule(): void
    {
        app(ModuleConfigService::class)->toggleModule($this->site, 'database_cleanup', true);
        unset($this->isModuleActive);
    }

    #[Computed]
    public function latestHealthCheck()
    {
        return $this->site->latestDatabaseHealthCheck;
    }

    #[Computed]
    public function healthIssues(): array
    {
        return $this->latestHealthCheck?->issues ?? [];
    }

    public function refreshHealth(): void
    {
        $this->dispatchTrackedJob('health', new CheckDatabaseHealthJob($this->site), 'Checking database health...');
        unset($this->latestHealthCheck, $this->healthIssues);
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->latestHealthCheck, $this->healthIssues);
    }

    #[Computed]
    public function cleanupHistory()
    {
        return $this->site->databaseCleanups()
            ->orderByDesc('cleaned_at')
            ->limit(20)
            ->get();
    }

    public function loadStats(): void
    {
        $this->statsLoading = true;

        try {
            $this->stats = DatabaseCleanupService::getStats($this->site);
        } catch (\Exception $e) {
            session()->flash('db-error', "Failed to load stats: {$e->getMessage()}");
            $this->stats = null;
        }

        $this->statsLoading = false;
    }

    public function confirmCleanup(): void
    {
        $this->showConfirmation = true;
        $this->dispatch('open-modal-confirm-cleanup');
    }

    public function runCleanup(): void
    {
        $options = [
            'revisions' => $this->cleanRevisions,
            'auto_drafts' => $this->cleanAutoDrafts,
            'trash_posts' => $this->cleanTrashPosts,
            'spam_comments' => $this->cleanSpamComments,
            'trash_comments' => $this->cleanTrashComments,
            'transients' => $this->cleanTransients,
            'orphaned_meta' => $this->cleanOrphanedMeta,
        ];

        $cleanup = DatabaseCleanupService::run($this->site, $options);

        if ($cleanup->status === 'completed') {
            session()->flash('db-success', "Cleanup completed: {$cleanup->total_deleted} items deleted, {$cleanup->formatted_space_saved} saved.");
        } else {
            session()->flash('db-error', "Cleanup failed: {$cleanup->error_message}");
        }

        $this->showConfirmation = false;
        $this->dispatch('close-modal-confirm-cleanup');
        $this->stats = null;
        unset($this->cleanupHistory);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-database-cleanup')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Database',
            ]);
    }
}
