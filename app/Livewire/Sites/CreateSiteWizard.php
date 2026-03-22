<?php

namespace App\Livewire\Sites;

use App\Livewire\Forms\SiteWizardFormData;
use App\Models\Client;
use App\Models\MaintenancePlan;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Services\ModuleConfigService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateSiteWizard extends Component
{
    public int $step = 1;

    public SiteWizardFormData $form;

    public ?string $connectivityStatus = null; // null, 'checking', 'ok', 'error'

    public ?string $connectivityMessage = null;

    // Step 2: Client
    public bool $creatingClient = false;

    public function mount(): void
    {
        $defaultPlan = MaintenancePlan::getDefault();
        if ($defaultPlan) {
            $this->form->planId = $defaultPlan->id;
        }
    }

    #[Computed]
    public function clients()
    {
        return Client::where('status', 'active')
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->whereHas('sites', fn ($sq) => $sq->where('user_id', auth()->id()))
            )
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function plans()
    {
        return MaintenancePlan::with('planModules')->orderBy('sort_order')->get();
    }

    public function updatedFormUrl(): void
    {
        $this->connectivityStatus = null;
        $this->connectivityMessage = null;

        // Auto-fill name from URL hostname
        if ($this->form->url && empty($this->form->name)) {
            $host = parse_url($this->form->url, PHP_URL_HOST);
            if ($host) {
                $this->form->name = $host;
            }
        }
    }

    public function checkConnectivity(): void
    {
        $this->form->validateUrl();

        $this->connectivityStatus = 'checking';

        try {
            $ch = curl_init($this->form->url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_NOBODY => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($error) {
                $this->connectivityStatus = 'error';
                $isSslError = in_array($errno, [
                    CURLE_SSL_CERTPROBLEM,
                    CURLE_SSL_CIPHER,
                    CURLE_PEER_FAILED_VERIFICATION,
                    CURLE_SSL_PINNEDPUBKEYNOTMATCH,
                    60, // CURLE_SSL_CACERT
                    51, // CURLE_SSL_PEER_CERTIFICATE
                ]);
                $this->connectivityMessage = $isSslError
                    ? "SSL certificate error: {$error}. Ensure the site has a valid SSL certificate."
                    : "Could not connect: {$error}";
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
                $this->form->validateStep1();
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
        $this->form->validateNewClient();

        $client = Client::create([
            'name' => $this->form->newClientName,
            'email' => $this->form->newClientEmail ?: null,
            'status' => 'active',
        ]);

        $this->form->clientId = $client->id;
        $this->creatingClient = false;
        $this->form->newClientName = '';
        $this->form->newClientEmail = '';
        unset($this->clients);
    }

    public function createSite(): void
    {
        $this->form->validate();

        $site = Site::create([
            'name' => $this->form->name,
            'url' => $this->form->url,
            'user_id' => auth()->id(),
            'client_id' => $this->form->clientId,
            'maintenance_plan_id' => $this->form->planId,
            'type' => 'wordpress',
            'status' => 'pending',
        ]);

        // Apply plan if selected
        if ($this->form->planId) {
            $plan = MaintenancePlan::find($this->form->planId);
            if ($plan) {
                app(ModuleConfigService::class)->applyPlan($site, $plan);
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
