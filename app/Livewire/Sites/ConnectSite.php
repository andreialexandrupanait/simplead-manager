<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\SyncWordPressSite;
use App\Models\Client;
use App\Models\MaintenancePlan;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Services\RiskyPluginPopulator;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * SPEC §10 — "Un singur ecran, nu un wizard."
 *
 *   1. Lipești URL-ul
 *   2. Se instalează conectorul
 *   3. Detectare automată: versiune WP, PHP, pluginuri, WooCommerce, plugin de
 *      formulare, disponibilitate Search Console
 *   4. Aplicația propune un profil complet
 *   5. Confirmi sau ajustezi
 *   6. La salvare: primul backup + scanarea de referință
 *
 * The old {@see CreateSiteWizard} was four gated steps — the exact pattern the
 * spec forbids — and it collected nothing but a name, a client and a plan: no
 * credentials, no detection, no proposal. A site created through it was not
 * connected to anything, and someone had to go find the connect dialog on the
 * site page afterwards.
 *
 * Step 2 ("se instalează conectorul") stays manual on purpose: installing a
 * plugin needs WordPress credentials Manager deliberately does not hold. The
 * screen instead states plainly what to install and where to get the key, then
 * verifies it live.
 *
 * Step 6 is not re-implemented here: SyncWordPressSite already runs the
 * reference scan, the first backup, the preset package, the key URLs and the
 * risk list on a site's first successful connect. Dispatching the sync IS
 * step 6.
 */
class ConnectSite extends Component
{
    public string $url = '';

    public string $name = '';

    public string $apiKey = '';

    public string $apiSecret = '';

    public ?int $clientId = null;

    public ?int $planId = null;

    /** null | 'probing' | 'ok' | 'error' */
    public ?string $detectionStatus = null;

    public ?string $detectionMessage = null;

    /**
     * What the connector told us about the site — the raw material for the
     * proposed profile.
     *
     * @var array<string, mixed>
     */
    public array $detected = [];

    public function mount(): void
    {
        $this->planId = MaintenancePlan::getDefault()?->id;
    }

    #[Computed]
    public function clients()
    {
        return Client::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function plans()
    {
        return MaintenancePlan::orderBy('sort_order')->get();
    }

    public function updatedUrl(): void
    {
        $this->detectionStatus = null;
        $this->detected = [];

        if ($this->url !== '' && $this->name === '') {
            $host = parse_url($this->url, PHP_URL_HOST);
            if ($host) {
                $this->name = $host;
            }
        }
    }

    /**
     * SPEC §10 step 3 — ask the connector what this site actually is, before
     * anything is written. Runs against a throwaway, unsaved Site so a failed
     * probe leaves no half-created record behind.
     */
    public function detect(): void
    {
        $this->validate([
            'url' => ['required', 'url', 'max:255', Rule::unique('sites', 'url')->whereNull('deleted_at')],
            'apiKey' => ['required', 'min:10'],
            'apiSecret' => ['required', 'min:10'],
        ]);

        $this->detectionStatus = 'probing';
        $this->detected = [];

        $probe = new Site([
            'url' => $this->url,
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
            'api_endpoint' => rtrim($this->url, '/').'/wp-json/simplead/v1',
        ]);

        try {
            $api = app(WordPressApiServiceFactory::class)->make($probe);
            $info = $api->getInfo();
            $plugins = $api->getPlugins()['plugins'] ?? [];
        } catch (\Throwable $e) {
            $this->detectionStatus = 'error';
            $this->detectionMessage = $this->explain($e);
            Log::info("Connect-site detection failed for {$this->url}: {$e->getMessage()}");

            return;
        }

        $slugs = array_map(
            fn ($p) => strtolower((string) ($p['slug'] ?? '')),
            is_array($plugins) ? $plugins : []
        );

        $this->detected = [
            'wp_version' => $info['wp_version'] ?? null,
            'php_version' => $info['php_version'] ?? null,
            'connector_version' => $info['plugin_version'] ?? null,
            'is_multisite' => (bool) ($info['is_multisite'] ?? false),
            'plugin_count' => count($slugs),
            'has_woocommerce' => in_array('woocommerce', $slugs, true),
            'form_plugin' => $this->detectFormPlugin($slugs),
            'risky_plugins' => $this->previewRiskyPlugins($slugs),
        ];

        $this->detectionStatus = 'ok';
        $this->detectionMessage = null;
    }

    /**
     * SPEC §10 steps 5-6 — save the confirmed profile and let the first-connect
     * pipeline do the rest.
     */
    public function connect(): void
    {
        abort_unless((bool) auth()->user()?->canManageSites(), 403, 'Viewers cannot connect sites.');

        $this->validate([
            'url' => ['required', 'url', 'max:255', Rule::unique('sites', 'url')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'apiKey' => ['required', 'min:10'],
            'apiSecret' => ['required', 'min:10'],
            'clientId' => ['nullable', 'exists:clients,id'],
            'planId' => ['nullable', 'exists:maintenance_plans,id'],
        ]);

        // Refusing to connect an unverified site would be worse than useless —
        // it would create a site record that silently never syncs.
        if ($this->detectionStatus !== 'ok') {
            $this->addError('apiKey', __('Run the check first — a site is only connected once the connector answers.'));

            return;
        }

        $site = Site::create([
            'name' => $this->name,
            'url' => $this->url,
            'user_id' => auth()->id(),
            'client_id' => $this->clientId,
            'maintenance_plan_id' => $this->planId,
            'type' => 'wordpress',
            'status' => 'active',
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
            'api_endpoint' => rtrim($this->url, '/').'/wp-json/simplead/v1',
        ]);

        SiteHealthState::firstOrCreate(
            ['site_id' => $site->id],
            ['circuit_state' => 'closed', 'consecutive_failures' => 0, 'circuit_breaks_last_24h' => 0],
        );

        // The first sync applies the Standard SimpleAD package, derives the key
        // URLs, populates the risk list, runs the reference security scan and
        // takes the first backup (§10 step 6, §13, §7.3, §7.5).
        SyncWordPressSite::dispatch($site);

        session()->flash('message', __('":name" connected — the reference scan and first backup are running now.', ['name' => $site->name]));
        $this->redirect(route('sites.overview', $site), navigate: true);
    }

    /**
     * Which plugins the risk list will pick up, shown BEFORE saving so the
     * proposed profile is something you can disagree with (§10 step 5).
     *
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function previewRiskyPlugins(array $slugs): array
    {
        $populator = app(RiskyPluginPopulator::class);

        // A throwaway in-memory site keeps this a pure preview — nothing is
        // written until connect().
        return array_values(array_filter(
            $slugs,
            fn (string $slug): bool => $slug !== '' && $populator->wouldFlag($slug),
        ));
    }

    /**
     * @param  list<string>  $slugs
     */
    private function detectFormPlugin(array $slugs): ?string
    {
        foreach (['contact-form-7', 'wpforms-lite', 'wpforms', 'gravityforms', 'ninja-forms', 'fluentform', 'forminator'] as $known) {
            foreach ($slugs as $slug) {
                if (str_contains($slug, $known)) {
                    return $slug;
                }
            }
        }

        return null;
    }

    private function explain(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, '404')) {
            return __('The connector plugin does not answer at this URL — is SimpleAd Manager Connector installed and active?');
        }

        if (str_contains($message, '401') || str_contains($message, '403')) {
            return __('The connector answered but rejected the credentials — check the API key and secret.');
        }

        return __('Could not reach the site: :error', ['error' => $message]);
    }

    public function render()
    {
        return view('livewire.sites.connect-site')
            ->layout('components.layouts.app', ['title' => __('Conectează un site')]);
    }
}
