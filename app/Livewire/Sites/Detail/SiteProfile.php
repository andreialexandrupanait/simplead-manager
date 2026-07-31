<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\SiteRiskyPlugin;
use App\Services\KeyUrlService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * SPEC §9's "Profil" — the per-site half of the plan/profile split:
 * "ce nu se poate împărți: URL-uri cheie, selector-canar, listă de risc, adresă
 * de test formulare".
 *
 * It did not exist as a screen, and three of its four fields could not be edited
 * from anywhere in the app:
 *   - key_urls were derived and overwritten on every sync, with no way to pin one
 *   - smoke_canary_selector was read by the smoke check and written by nothing,
 *     so it was NULL on every site and always fell back to '<body'
 *   - site_risky_plugins supported a protected source='manual' row that no screen
 *     could create
 *   - the form-test address had nowhere to live at all
 */
class SiteProfile extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    /** One URL per line — a textarea is the honest shape for an ordered short list. */
    public string $keyUrls = '';

    public string $canarySelector = '';

    public string $formTestEmail = '';

    public string $newRiskySlug = '';

    public string $newRiskyReason = '';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);

        $this->site = $site;
        $this->keyUrls = implode("\n", (array) ($site->key_urls ?? []));
        $this->canarySelector = (string) ($site->smoke_canary_selector ?? '');
        $this->formTestEmail = (string) ($site->form_test_email ?? '');
    }

    #[Computed]
    public function riskyPlugins()
    {
        return SiteRiskyPlugin::where('site_id', $this->site->id)
            ->orderBy('source')
            ->orderBy('slug')
            ->get();
    }

    #[Computed]
    public function derivedPreview(): array
    {
        return app(KeyUrlService::class)->derive($this->site);
    }

    /**
     * Pin the listed URLs. From here on the quarterly recompute leaves them alone;
     * without the lock the next sync would erase the choice within the hour.
     */
    public function saveKeyUrls(): void
    {
        $this->authorizeSiteModification($this->site);

        $urls = collect(preg_split('/\R/', $this->keyUrls) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->filter(fn ($line) => $line !== '' && filter_var($line, FILTER_VALIDATE_URL) !== false)
            ->values()
            ->all();

        app(KeyUrlService::class)->lock($this->site->refresh(), $urls);

        $this->site->refresh();
        $this->keyUrls = implode("\n", (array) ($this->site->key_urls ?? []));

        $this->dispatch('notify', type: 'success', message: __('Key URLs pinned. Automatic recalculation will leave them alone.'));
    }

    public function unlockKeyUrls(): void
    {
        $this->authorizeSiteModification($this->site);

        $service = app(KeyUrlService::class);
        $service->unlock($this->site);
        $urls = $service->deriveAndStore($this->site->refresh());

        $this->site->refresh();
        $this->keyUrls = implode("\n", $urls);

        $this->dispatch('notify', type: 'success', message: __('Back to automatic. Key URLs recalculated.'));
    }

    /**
     * The canary selector is what the smoke check looks for to decide the page
     * rendered rather than half-rendered. Empty means the '<body' fallback, which
     * proves considerably less.
     */
    public function saveSmokeSettings(): void
    {
        $this->authorizeSiteModification($this->site);

        $this->validate([
            'canarySelector' => ['nullable', 'string', 'max:255'],
            'formTestEmail' => ['nullable', 'email', 'max:191'],
        ]);

        $this->site->update([
            'smoke_canary_selector' => $this->canarySelector !== '' ? $this->canarySelector : null,
            'form_test_email' => $this->formTestEmail !== '' ? $this->formTestEmail : null,
        ]);

        $this->dispatch('notify', type: 'success', message: __('Profile saved.'));
    }

    /**
     * Manual risk-list entries are never overwritten by the automatic populator —
     * that protection already existed and had no way to be used.
     */
    public function addRiskyPlugin(): void
    {
        $this->authorizeSiteModification($this->site);

        $this->validate([
            'newRiskySlug' => ['required', 'string', 'max:191'],
            'newRiskyReason' => ['nullable', 'string', 'max:255'],
        ]);

        SiteRiskyPlugin::updateOrCreate(
            ['site_id' => $this->site->id, 'slug' => trim($this->newRiskySlug)],
            [
                'is_risky' => true,
                'source' => 'manual',
                'reason' => $this->newRiskyReason !== '' ? $this->newRiskyReason : __('Marked by hand'),
            ],
        );

        $this->newRiskySlug = '';
        $this->newRiskyReason = '';
        unset($this->riskyPlugins);

        $this->dispatch('notify', type: 'success', message: __('Added to the risk list.'));
    }

    public function removeRiskyPlugin(int $id): void
    {
        $this->authorizeSiteModification($this->site);

        SiteRiskyPlugin::where('site_id', $this->site->id)->whereKey($id)->delete();

        unset($this->riskyPlugins);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-profile')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — '.__('Profile'),
            ]);
    }
}
