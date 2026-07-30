<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\ReportTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

trait WithTemplateForm
{
    public ?int $editingTemplateId = null;

    public string $name = '';

    public string $description = '';

    public array $sections = [];

    public array $section_overrides = [];

    public array $section_options = [];

    public array $expandedSections = [];

    public string $company_name = '';

    public string $company_website = '';

    public string $primary_color = '#3b82f6';

    public string $intro_text = '';

    public string $closing_text = '';

    public string $language = 'ro';

    /**
     * Maps section keys used in the $sections array to the section_options keys
     * used in partials. Some keys differ (e.g. 'uptime' -> 'technical_stability').
     */
    protected static array $sectionKeyMap = [
        'overview' => 'executive_snapshot',
        'uptime' => 'technical_stability',
        'updates' => 'updates',
        'backups' => 'backups',
        'tasks' => 'tasks',
        'analytics' => 'analytics',
        'search_console' => 'search_console',
        'performance' => 'performance',
        'infrastructure' => 'infrastructure',
        'plugin_inventory' => 'plugin_inventory',
        'database_health' => 'database_health',
        'cloudflare' => 'cloudflare',
        'wp_users' => 'wp_users',
        'security_checks' => 'security_checks',
        'recommendations' => 'recommendations',
        'seo' => 'seo',
    ];

    /**
     * Single source of truth for sub-section toggles per section.
     *
     * The values are the labels the settings screen prints, so they go through
     * __() here — that keeps the literal keys where a translation extractor can
     * find them instead of hiding them behind __($variable) in the blade.
     */
    public static function sectionSubOptions(): array
    {
        return [
            'executive_snapshot' => [
                'show_uptime' => __('Uptime'),
                'show_downtime' => __('Downtime'),
                'show_updates' => __('Updates'),
                'show_backups' => __('Backups'),
                'show_desktop_perf' => __('Desktop Performance'),
                'show_mobile_perf' => __('Mobile Performance'),
                'show_users' => __('Users (Analytics)'),
                'show_impressions' => __('Impressions (Search Console)'),
            ],
            'technical_stability' => [
                'show_incidents_table' => __('Incidents Table'),
                'show_security' => __('Security Sub-card'),
                'show_database' => __('Database Sub-card'),
            ],
            'updates' => [
                'show_breakdown_chart' => __('Breakdown Chart'),
                'show_log_table' => __('Update Log Table'),
            ],
            'backups' => [
                'show_chart' => __('Donut Chart'),
                'show_history_table' => __('Backup History Table'),
            ],
            'analytics' => [
                'show_daily_chart' => __('Daily Users Chart'),
                'show_traffic_sources' => __('Traffic Sources Chart'),
                'show_top_pages' => __('Top Pages Table'),
                'show_devices' => __('Device Distribution'),
                'show_countries' => __('Top Countries'),
            ],
            'search_console' => [
                'show_performance_chart' => __('Performance Chart'),
                'show_queries_table' => __('Top Queries Table'),
            ],
            'performance' => [
                'show_mobile' => __('Mobile Score'),
                'show_desktop' => __('Desktop Score'),
            ],
            'infrastructure' => [
                'show_ssl' => __('SSL Certificate'),
                'show_domain' => __('Domain Registration'),
                'show_email' => __('Email Deliverability'),
            ],
            'recommendations' => [
                'show_technical' => __('Technical Recommendations'),
                'show_performance' => __('Performance Recommendations'),
                'show_seo' => __('SEO Recommendations'),
            ],
            'plugin_inventory' => [],
            'database_health' => [],
            'cloudflare' => [],
            'wp_users' => [],
            'security_checks' => [],
            'seo' => [
                'show_issues' => __('Issue Summary'),
                'show_recommendations' => __('Priority Recommendations'),
                'show_score_trend' => __('Score Trend Chart'),
                'show_ssl_status' => __('SSL Certificate'),
                'show_security_headers' => __('Security Headers'),
                'show_broken_links' => __('Broken Links'),
                'show_sitemap' => __('Sitemap Analysis'),
                'show_robots' => __('Robots.txt Analysis'),
                'show_top_pages' => __('Top Pages Overview'),
                'show_structured_data' => __('Structured Data'),
                'show_internal_linking' => __('Internal Linking'),
                'show_images' => __('Image Optimization'),
                'show_social' => __('Social Meta Coverage'),
            ],
        ];
    }

    /**
     * Get the options key for a given section key from the sections array.
     */
    public static function optionsKeyForSection(string $sectionKey): ?string
    {
        return static::$sectionKeyMap[$sectionKey] ?? $sectionKey;
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'sections' => 'required|array|min:1',
            'section_overrides.*.title' => 'nullable|string|max:255',
            'section_overrides.*.description' => 'nullable|string|max:1000',
            'section_options.*.*' => 'boolean',
            'company_name' => 'nullable|string|max:255',
            'company_website' => 'nullable|string|max:255',
            'primary_color' => 'required|string|max:7',
            'intro_text' => 'nullable|string',
            'closing_text' => 'nullable|string',
            'language' => 'required|string|in:ro,en',
        ];
    }

    public function toggleSectionExpand(string $key): void
    {
        if (in_array($key, $this->expandedSections)) {
            $this->expandedSections = array_values(array_diff($this->expandedSections, [$key]));
        } else {
            $this->expandedSections[] = $key;
        }
    }

    /**
     * Resolve a template id that arrived from the browser.
     *
     * Every id-taking action here is triggered by a wire:click carrying a
     * client-supplied integer, so an unknown id has to produce a message
     * instead of a 404 page or a silent no-op.
     */
    protected function findTemplateOrNotify(int $id): ?ReportTemplate
    {
        $validator = Validator::make(
            ['id' => $id],
            ['id' => ['required', 'integer', 'exists:report_templates,id']]
        );

        if ($validator->fails()) {
            $this->dispatch('notify', type: 'error', message: __('Template not found.'));

            return null;
        }

        return ReportTemplate::find($id);
    }

    public function openCreateForm(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->initializeSubOptionDefaults();
        $this->dispatch('open-modal-template-form');
    }

    public function editTemplate(int $id): void
    {
        $template = $this->findTemplateOrNotify($id);

        if (! $template) {
            return;
        }

        $this->resetValidation();
        $this->editingTemplateId = $template->id;
        $this->name = $template->name;
        $this->description = $template->description ?? '';
        $this->sections = $template->sections ?? [];
        $this->section_overrides = $template->section_overrides ?? [];
        $this->section_options = $template->section_options ?? [];
        $this->company_name = $template->company_name ?? '';
        $this->company_website = $template->company_website ?? '';
        $this->primary_color = $template->primary_color ?? '#3b82f6';
        $this->intro_text = $template->intro_text ?? '';
        $this->closing_text = $template->closing_text ?? '';
        $this->language = $template->language ?? 'ro';
        $this->expandedSections = [];
        $this->initializeSubOptionDefaults();
        $this->dispatch('open-modal-template-form');
    }

    public function saveTemplate(): void
    {
        $this->validate();

        $data = [
            'name' => $this->name,
            'description' => $this->description ?: null,
            'sections' => $this->sections,
            'section_overrides' => $this->cleanSectionOverrides() ?: null,
            'section_options' => $this->cleanSectionOptions() ?: null,
            'company_name' => $this->company_name ?: null,
            'company_website' => $this->company_website ?: null,
            'primary_color' => $this->primary_color,
            'intro_text' => $this->intro_text ?: null,
            'closing_text' => $this->closing_text ?: null,
            'language' => $this->language,
        ];

        if ($this->editingTemplateId) {
            $template = $this->findTemplateOrNotify($this->editingTemplateId);

            if (! $template) {
                return;
            }

            $template->update($data);
            $this->dispatch('notify', type: 'success', message: __('Template updated.'));
        } else {
            ReportTemplate::create($data);
            $this->dispatch('notify', type: 'success', message: __('Template created.'));
        }

        $this->dispatch('close-modal-template-form');
        $this->resetForm();
    }

    public function duplicateTemplate(int $id): void
    {
        $template = $this->findTemplateOrNotify($id);

        if (! $template) {
            return;
        }

        $new = $template->replicate();
        $new->name = $template->name.' (Copy)';
        $new->is_default = false;
        $new->save();

        $this->dispatch('notify', type: 'success', message: __('Template duplicated.'));
    }

    public function deleteTemplate(int $id): void
    {
        $template = $this->findTemplateOrNotify($id);

        if (! $template) {
            return;
        }

        if ($template->schedules()->exists() || $template->sites()->exists()) {
            $this->dispatch('notify', type: 'error', message: __('Cannot delete — this template is assigned to sites or schedules.'));

            return;
        }

        $template->delete();
        $this->dispatch('notify', type: 'success', message: __('Template deleted.'));
        $this->resetPage();
    }

    public function setDefault(int $id): void
    {
        $template = $this->findTemplateOrNotify($id);

        if (! $template) {
            return;
        }

        // Both writes have to land together: an interrupted pair would leave the
        // install with no default template at all.
        DB::transaction(function () use ($template): void {
            ReportTemplate::where('is_default', true)->update(['is_default' => false]);
            $template->update(['is_default' => true]);
        });

        $this->dispatch('notify', type: 'success', message: __('Default template updated.'));
    }

    public function resetForm(): void
    {
        $this->editingTemplateId = null;
        $this->name = '';
        $this->description = '';
        $this->sections = ['overview', 'updates', 'uptime', 'infrastructure', 'backups', 'tasks', 'analytics', 'search_console', 'performance', 'plugin_inventory', 'database_health', 'cloudflare', 'wp_users', 'security_checks', 'recommendations'];
        $this->section_overrides = [];
        $this->section_options = [];
        $this->expandedSections = [];
        $this->company_name = '';
        $this->company_website = '';
        $this->primary_color = '#3b82f6';
        $this->intro_text = '';
        $this->closing_text = '';
        $this->language = 'ro';
    }

    public function cancelForm(): void
    {
        $this->dispatch('close-modal-template-form');
        $this->resetForm();
    }

    /**
     * Fill missing sub-option keys with true (default) for checkbox binding.
     */
    protected function initializeSubOptionDefaults(): void
    {
        foreach (static::sectionSubOptions() as $key => $options) {
            foreach ($options as $optionKey => $label) {
                if (! isset($this->section_options[$key][$optionKey])) {
                    $this->section_options[$key][$optionKey] = true;
                }
            }
        }
    }

    /**
     * Strip empty override entries before saving (only store non-defaults).
     */
    protected function cleanSectionOverrides(): array
    {
        $cleaned = [];
        foreach ($this->section_overrides as $key => $override) {
            $title = trim($override['title'] ?? '');
            $desc = trim($override['description'] ?? '');
            if ($title !== '' || $desc !== '') {
                $entry = [];
                if ($title !== '') {
                    $entry['title'] = $title;
                }
                if ($desc !== '') {
                    $entry['description'] = $desc;
                }
                $cleaned[$key] = $entry;
            }
        }

        return $cleaned;
    }

    /**
     * Strip all-true entries before saving (only store non-defaults).
     */
    protected function cleanSectionOptions(): array
    {
        $cleaned = [];
        foreach ($this->section_options as $key => $options) {
            $nonDefaults = [];
            foreach ($options as $optionKey => $value) {
                if (! (bool) $value) {
                    $nonDefaults[$optionKey] = false;
                }
            }
            if (! empty($nonDefaults)) {
                $cleaned[$key] = $nonDefaults;
            }
        }

        return $cleaned;
    }
}
