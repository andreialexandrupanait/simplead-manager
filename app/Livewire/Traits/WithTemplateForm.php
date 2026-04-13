<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\ReportTemplate;

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
     */
    public static function sectionSubOptions(): array
    {
        return [
            'executive_snapshot' => [
                'show_uptime' => 'Uptime',
                'show_downtime' => 'Downtime',
                'show_updates' => 'Updates',
                'show_backups' => 'Backups',
                'show_desktop_perf' => 'Desktop Performance',
                'show_mobile_perf' => 'Mobile Performance',
                'show_users' => 'Users (Analytics)',
                'show_impressions' => 'Impressions (Search Console)',
            ],
            'technical_stability' => [
                'show_incidents_table' => 'Incidents Table',
                'show_security' => 'Security Sub-card',
                'show_database' => 'Database Sub-card',
            ],
            'updates' => [
                'show_breakdown_chart' => 'Breakdown Chart',
                'show_log_table' => 'Update Log Table',
            ],
            'backups' => [
                'show_chart' => 'Donut Chart',
                'show_history_table' => 'Backup History Table',
            ],
            'analytics' => [
                'show_daily_chart' => 'Daily Users Chart',
                'show_traffic_sources' => 'Traffic Sources Chart',
                'show_top_pages' => 'Top Pages Table',
                'show_devices' => 'Device Distribution',
                'show_countries' => 'Top Countries',
            ],
            'search_console' => [
                'show_performance_chart' => 'Performance Chart',
                'show_queries_table' => 'Top Queries Table',
            ],
            'performance' => [
                'show_mobile' => 'Mobile Score',
                'show_desktop' => 'Desktop Score',
            ],
            'infrastructure' => [
                'show_ssl' => 'SSL Certificate',
                'show_domain' => 'Domain Registration',
                'show_email' => 'Email Deliverability',
            ],
            'recommendations' => [
                'show_technical' => 'Technical Recommendations',
                'show_performance' => 'Performance Recommendations',
                'show_seo' => 'SEO Recommendations',
            ],
            'plugin_inventory' => [],
            'database_health' => [],
            'cloudflare' => [],
            'wp_users' => [],
            'security_checks' => [],
            'seo' => [
                'show_issues' => 'Issue Summary',
                'show_recommendations' => 'Priority Recommendations',
                'show_score_trend' => 'Score Trend Chart',
                'show_ssl_status' => 'SSL Certificate',
                'show_security_headers' => 'Security Headers',
                'show_broken_links' => 'Broken Links',
                'show_sitemap' => 'Sitemap Analysis',
                'show_robots' => 'Robots.txt Analysis',
                'show_top_pages' => 'Top Pages Overview',
                'show_structured_data' => 'Structured Data',
                'show_internal_linking' => 'Internal Linking',
                'show_images' => 'Image Optimization',
                'show_social' => 'Social Meta Coverage',
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

    public function openCreateForm(): void
    {
        $this->resetForm();
        $this->initializeSubOptionDefaults();
        $this->dispatch('open-modal-template-form');
    }

    public function editTemplate(int $id): void
    {
        $template = ReportTemplate::findOrFail($id);
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
            ReportTemplate::findOrFail($this->editingTemplateId)->update($data);
            session()->flash('template-success', 'Template updated.');
        } else {
            ReportTemplate::create($data);
            session()->flash('template-success', 'Template created.');
        }

        $this->dispatch('close-modal-template-form');
        $this->resetForm();
    }

    public function duplicateTemplate(int $id): void
    {
        $template = ReportTemplate::findOrFail($id);
        $new = $template->replicate();
        $new->name = $template->name.' (Copy)';
        $new->is_default = false;
        $new->save();
        session()->flash('template-success', 'Template duplicated.');
    }

    public function deleteTemplate(int $id): void
    {
        $template = ReportTemplate::findOrFail($id);

        if ($template->schedules()->exists() || $template->sites()->exists()) {
            session()->flash('template-error', 'Cannot delete — this template is assigned to sites or schedules.');

            return;
        }

        $template->delete();
        session()->flash('template-success', 'Template deleted.');
        $this->resetPage();
    }

    public function setDefault(int $id): void
    {
        ReportTemplate::where('is_default', true)->update(['is_default' => false]);
        ReportTemplate::findOrFail($id)->update(['is_default' => true]);
        session()->flash('template-success', 'Default template updated.');
    }

    public function resetForm(): void
    {
        $this->editingTemplateId = null;
        $this->name = '';
        $this->description = '';
        $this->sections = ['overview', 'updates', 'uptime', 'infrastructure', 'backups', 'analytics', 'search_console', 'performance', 'plugin_inventory', 'database_health', 'cloudflare', 'wp_users', 'security_checks', 'recommendations'];
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
