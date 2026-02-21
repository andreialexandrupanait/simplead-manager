<?php

namespace App\Livewire\Sites;

use App\Models\Client;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\SitePreset;
use App\Services\ModuleConfigService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateSiteWizard extends Component
{
    public int $step = 1;

    // Step 1: Site URL
    public string $url = '';
    public string $name = '';
    public ?string $connectivityStatus = null; // null, 'checking', 'ok', 'error'
    public ?string $connectivityMessage = null;

    // Step 2: Client
    public ?int $clientId = null;
    public bool $creatingClient = false;
    public string $newClientName = '';
    public string $newClientEmail = '';

    // Step 3: Preset
    public ?int $presetId = null;

    public function mount(): void
    {
        $defaultPreset = SitePreset::getDefault();
        if ($defaultPreset) {
            $this->presetId = $defaultPreset->id;
        }
    }

    #[Computed]
    public function clients()
    {
        return Client::where('status', 'active')
            ->when(!auth()->user()->isAdmin(), fn ($q) =>
                $q->whereHas('sites', fn ($sq) => $sq->where('user_id', auth()->id()))
            )
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function presets()
    {
        return SitePreset::with('presetModules')->orderBy('sort_order')->get();
    }

    public function updatedUrl(): void
    {
        $this->connectivityStatus = null;
        $this->connectivityMessage = null;

        // Auto-fill name from URL hostname
        if ($this->url && empty($this->name)) {
            $host = parse_url($this->url, PHP_URL_HOST);
            if ($host) {
                $this->name = $host;
            }
        }
    }

    public function checkConnectivity(): void
    {
        $this->validate([
            'url' => 'required|url',
        ]);

        $this->connectivityStatus = 'checking';

        try {
            $ch = curl_init($this->url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_NOBODY => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $this->connectivityStatus = 'error';
                $this->connectivityMessage = "Could not connect: {$error}";
            } elseif ($httpCode >= 200 && $httpCode < 400) {
                $this->connectivityStatus = 'ok';
                $this->connectivityMessage = "Site is reachable (HTTP {$httpCode})";
            } else {
                $this->connectivityStatus = 'error';
                $this->connectivityMessage = "Site returned HTTP {$httpCode}";
            }
        } catch (\Exception $e) {
            $this->connectivityStatus = 'error';
            $this->connectivityMessage = "Check failed: {$e->getMessage()}";
        }
    }

    public function goToStep(int $step): void
    {
        // Validate current step before advancing
        if ($step > $this->step) {
            if ($this->step === 1) {
                $this->validate([
                    'url' => 'required|url|max:255|unique:sites,url',
                    'name' => 'required|string|max:255',
                ]);
            }
        }

        $this->step = $step;
    }

    public function nextStep(): void
    {
        $this->goToStep($this->step + 1);
    }

    public function previousStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function createClient(): void
    {
        $this->validate([
            'newClientName' => 'required|string|max:255',
            'newClientEmail' => 'nullable|email|max:255',
        ]);

        $client = Client::create([
            'name' => $this->newClientName,
            'email' => $this->newClientEmail ?: null,
            'status' => 'active',
        ]);

        $this->clientId = $client->id;
        $this->creatingClient = false;
        $this->newClientName = '';
        $this->newClientEmail = '';
        unset($this->clients);
    }

    public function createSite(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:255|unique:sites,url',
            'clientId' => 'nullable|exists:clients,id',
            'presetId' => 'nullable|exists:site_presets,id',
        ]);

        $site = Site::create([
            'name' => $this->name,
            'url' => $this->url,
            'user_id' => auth()->id(),
            'client_id' => $this->clientId,
            'applied_preset_id' => $this->presetId,
            'type' => 'wordpress',
            'status' => 'pending',
        ]);

        // Apply preset if selected
        if ($this->presetId) {
            $preset = SitePreset::find($this->presetId);
            if ($preset) {
                app(ModuleConfigService::class)->applyPreset($site, $preset);
            }
        }

        // Create health state for circuit breaker
        SiteHealthState::create([
            'site_id' => $site->id,
            'circuit_state' => 'closed',
            'consecutive_failures' => 0,
            'circuit_breaks_last_24h' => 0,
        ]);

        session()->flash('message', "Site \"{$site->name}\" created successfully.");
        $this->redirect(route('sites.overview', $site), navigate: true);
    }

    public function render()
    {
        return view('livewire.sites.create-site-wizard')
            ->layout('components.layouts.app', ['title' => 'Add New Site']);
    }
}
