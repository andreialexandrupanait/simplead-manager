<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Traits\WithMaintenancePlanApply;
use App\Livewire\Traits\WithMaintenancePlanForm;
use App\Models\MaintenancePlan;
use App\Models\Site;
use App\Services\MaintenancePlanService;
use App\Services\ModuleConfigService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class MaintenancePlans extends Component
{
    use WithMaintenancePlanApply;
    use WithMaintenancePlanForm;

    // View mode: list, apply, create, edit, create_from_site
    public string $view = 'list';

    // Delete confirmation
    public ?int $confirmDeleteId = null;

    public function mount(): void
    {
        $this->initModuleForm();
        $this->initSecurityToggles();
        $this->initTweakToggles();
    }

    #[Computed]
    public function plans()
    {
        return MaintenancePlan::with('planModules')
            ->withCount('sites')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function sites()
    {
        $query = Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name');

        if ($this->siteSearch) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->siteSearch}%")
                    ->orWhere('url', 'ilike', "%{$this->siteSearch}%");
            });
        }

        return $query->get();
    }

    #[Computed]
    public function sourceSites()
    {
        return Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function moduleKeys(): array
    {
        return ModuleConfigService::getModuleKeys();
    }

    #[Computed]
    public function moduleLabels(): array
    {
        return [
            'uptime' => 'Uptime Monitoring',
            'backup' => 'Backups',
            'ssl' => 'SSL Monitoring',
            'performance' => 'Performance Tests',
            'security' => 'Security Scans',
            'analytics' => 'Google Analytics',
            'search_console' => 'Search Console',
            'cloudflare' => 'Cloudflare',
            'database_cleanup' => 'Database Cleanup',
        ];
    }

    #[Computed]
    public function securitySettingLabels(): array
    {
        return [
            'hardening' => [
                'title' => 'WordPress Hardening',
                'settings' => [
                    'disable_theme_editor' => 'Disable Theme/Plugin Editor',
                    'disable_user_enumeration' => 'Disable User Enumeration',
                    'hide_wp_version' => 'Hide WordPress Version',
                    'restrict_xmlrpc' => 'Restrict XML-RPC',
                    'security_headers' => 'Security Headers',
                    'block_application_passwords' => 'Block Application Passwords',
                    'restrict_rest_api' => 'Restrict REST API',
                ],
            ],
            'htaccess' => [
                'title' => '.htaccess Rules',
                'settings' => [
                    'block_default_files' => 'Block Default Files',
                    'block_readme_access' => 'Block Readme Access',
                    'block_debug_log' => 'Block Debug Log',
                    'disable_directory_listing' => 'Disable Directory Listing',
                    'firewall_enabled' => 'Basic Firewall',
                ],
            ],
            'login' => [
                'title' => 'Login Protection',
                'settings' => [
                    'brute_force_protection' => 'Brute Force Protection',
                ],
            ],
        ];
    }

    #[Computed]
    public function tweakSettingLabels(): array
    {
        return [
            'performance' => [
                'title' => 'Performance',
                'settings' => [
                    'heartbeat_control' => 'Heartbeat Control',
                    'revisions_control' => 'Limit Post Revisions',
                    'image_upload_control' => 'Image Optimization',
                    'disable_emojis' => 'Disable Emojis',
                    'disable_dashicons' => 'Disable Dashicons',
                    'disable_jquery_migrate' => 'Disable jQuery Migrate',
                    'disable_generator_tag' => 'Disable Generator Tag',
                    'disable_wlw_manifest' => 'Disable WLW Manifest',
                    'disable_rsd_link' => 'Disable RSD Link',
                    'disable_shortlinks' => 'Disable Shortlinks',
                    'disable_lazy_load' => 'Disable Native Lazy Load',
                    'disable_block_widgets' => 'Disable Block Widgets',
                ],
            ],
            'site_control' => [
                'title' => 'Site Control',
                'settings' => [
                    'disable_all_updates' => 'Disable All Auto-Updates',
                    'disable_comments' => 'Disable Comments',
                    'disable_feeds' => 'Disable RSS Feeds',
                    'disable_embeds' => 'Disable Embeds',
                    'redirect_404' => 'Redirect 404 to Homepage',
                    'disable_gutenberg' => 'Disable Gutenberg Editor',
                    'disable_author_archives' => 'Disable Author Archives',
                ],
            ],
        ];
    }

    // --- Bulk Apply ---

    public function applyPlanToAll(int $planId): void
    {
        $plan = \App\Models\MaintenancePlan::findOrFail($planId);
        $sites = \App\Models\Site::where('is_connected', true)->whereNull('maintenance_plan_id')->get();
        foreach ($sites as $site) {
            app(\App\Services\ModuleConfigService::class)->applyPlan($site, $plan);
        }
        $this->dispatch('notify', type: 'success', message: "Applied plan to {$sites->count()} sites.");
    }

    // --- Delete ---

    public function confirmDelete(int $id): void
    {
        $this->confirmDeleteId = $id;
    }

    public function delete(): void
    {
        if (! $this->confirmDeleteId) {
            return;
        }

        $result = app(MaintenancePlanService::class)->deletePlan($this->confirmDeleteId);
        $this->confirmDeleteId = null;

        if (! $result['success']) {
            $this->dispatch('notify', type: 'error', message: $result['message']);

            return;
        }

        unset($this->plans);
        $this->dispatch('notify', type: 'success', message: $result['message']);
    }

    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
    }

    // --- Navigation ---

    public function backToList(): void
    {
        $this->view = 'list';
        $this->resetForm();
        $this->applyingPlanId = null;
        $this->selectedSiteIds = [];
        $this->selectAll = false;
        $this->siteSearch = '';
        $this->confirmDeleteId = null;
        unset($this->plans, $this->sites);
    }

    public function render()
    {
        return view('livewire.maintenance-plans')
            ->layout('components.layouts.app', [
                'title' => 'Maintenance Plans',
            ]);
    }
}
