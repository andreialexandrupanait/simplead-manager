<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\SiteRiskyPlugin;
use App\Services\ContactFormChecker;
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

    public bool $formTestEnabled = false;

    public string $formTestFormId = '';

    /** Submittable forms, for the picker. */
    public array $formOptions = [];

    /** Everything the site reported, submittable or not, grouped in the view. */
    public array $discoveredForms = [];

    public ?string $formsError = null;

    public string $newRiskySlug = '';

    public string $newRiskyReason = '';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);

        $this->site = $site;
        $this->keyUrls = implode("\n", (array) ($site->key_urls ?? []));
        $this->canarySelector = (string) ($site->smoke_canary_selector ?? '');
        $this->formTestEmail = (string) ($site->form_test_email ?? '');
        $this->formTestEnabled = (bool) $site->form_test_enabled;
        $this->formTestFormId = (string) ($site->form_test_form_id ?? '');
    }

    /**
     * Ask the site which forms it has, so the operator can pick the contact one.
     * Safe by construction: capability() is a pure probe and submits nothing.
     */
    public function detectForms(): void
    {
        $this->authorizeSiteModification($this->site);

        $this->formsError = null;

        $checker = app(ContactFormChecker::class);

        // Ask the SITE what forms it has, rather than asking the plugin list what
        // it might have. A site whose forms are Elementor widgets has no form
        // plugin at all, and used to be refused on that basis alone.
        $discovered = $checker->discoverForms($this->site);

        if ($discovered['available']) {
            $this->discoveredForms = $discovered['forms'];
            $this->formOptions = collect($discovered['forms'])
                ->filter(fn (array $f) => ($f['submittable'] ?? false) && ($f['id'] ?? '') !== '')
                ->map(fn (array $f) => [
                    'id' => (string) $f['id'],
                    'title' => trim(($f['name'] ?? 'Form').' — '.($f['title'] ?? '')),
                ])
                ->values()
                ->all();

            if ($discovered['forms'] === []) {
                $this->formsError = __('Nothing on this site looks like a form. Nothing was submitted.');
            }

            return;
        }

        // Older connector: fall back to the per-plugin listing.
        $capability = $checker->capability($this->site);
        $this->formOptions = $capability['forms'];

        if ($this->formOptions === []) {
            $this->formsError = $discovered['reason']
                ?? __('The connector did not list any forms.');
        }
    }

    /** Whether this connector is new enough to list and target a specific form. */
    #[Computed]
    public function canChooseForm(): bool
    {
        return $this->site->connectorAtLeast(
            (string) config('monitoring.form_test.min_form_choice_version', '2.22.0')
        );
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
            'formTestFormId' => ['nullable', 'string', 'max:64'],
        ]);

        $this->site->update([
            'smoke_canary_selector' => $this->canarySelector !== '' ? $this->canarySelector : null,
            'form_test_email' => $this->formTestEmail !== '' ? $this->formTestEmail : null,
            'form_test_enabled' => $this->formTestEnabled,
            'form_test_form_id' => $this->formTestFormId !== '' ? $this->formTestFormId : null,
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
