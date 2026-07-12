<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ApplyPlanToSite;
use App\Models\BackupConfig;
use App\Models\MaintenancePlan;
use App\Models\MaintenancePlanModule;
use App\Models\SecuritySetting;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MaintenancePlanService
{
    public function __construct(
        private ModuleConfigService $moduleConfigService,
        private SecuritySettingsService $securityService,
        private SiteTweaksSettingsService $tweaksService,
    ) {}

    /**
     * Create a plan by snapshotting a site's current configuration.
     */
    public function createFromSite(
        Site $site,
        string $name,
        string $description,
        array $sections,
    ): MaintenancePlan {
        $plan = MaintenancePlan::create([
            'name' => $name,
            'description' => $description,
            'is_default' => false,
            'sort_order' => 0,
            'include_modules' => in_array('modules', $sections),
            'include_security' => in_array('security', $sections),
            'include_tweaks' => in_array('tweaks', $sections),
            'source_site_id' => $site->id,
            'created_by' => auth()->id(),
            'security_settings' => in_array('security', $sections) ? $this->snapshotSecuritySettings($site) : null,
            'tweak_settings' => in_array('tweaks', $sections) ? $this->snapshotTweakSettings($site) : null,
        ]);

        if (in_array('modules', $sections)) {
            $this->snapshotModules($site, $plan);
        }

        return $plan;
    }

    /**
     * Apply a plan to multiple sites.
     *
     * P1-60: a fleet apply no longer runs synchronously inside the web request
     * (which tied up the worker and could time out mid-way, leaving a partial
     * apply). Instead we dispatch ONE queued {@see ApplyPlanToSite} job per site
     * — each funnels through the same canonical {@see self::applyPlanToSite()} —
     * and return immediately with a batch id the UI can poll for progress.
     *
     * @param  array<int, string>  $sections
     * @return array{total: int, queued: int, batch_id: string}
     */
    public function applyToSites(MaintenancePlan $plan, Collection $sites, array $sections): array
    {
        $total = $sites->count();
        $batchId = (string) Str::uuid();

        $this->initProgress($batchId, $total, $plan->name);

        foreach ($sites as $site) {
            ApplyPlanToSite::dispatch($site, $plan, $sections, $batchId);
        }

        return [
            'total' => $total,
            'queued' => $total,
            'batch_id' => $batchId,
        ];
    }

    /**
     * Canonical per-site plan application (P1-58).
     *
     * This is the SINGLE source of truth for "apply this plan to this site".
     * The new-site created hook, the bulk UI apply, and the apply-to-unassigned
     * path all funnel through here so a managed site can never drift depending
     * on which entry point touched it. It applies modules AND security AND
     * tweaks AND the backup schedule, honoring the plan's include_* flags and
     * the bulk-safe security whitelist (enforced inside applySecuritySettings).
     *
     * @param  array<int, string>|null  $sections  Sections to apply; null = every section the plan carries.
     * @return array{connected: bool, pushed: bool}
     */
    public function applyPlanToSite(Site $site, MaintenancePlan $plan, ?array $sections = null): array
    {
        $sections ??= $this->planSections($plan);

        $needsSecurityPush = false;
        $needsTweaksPush = false;

        if (in_array('modules', $sections, true) && $plan->include_modules) {
            // Materializes module rows (uptime/backup/dns/…) incl. the default-on
            // DNS monitor with jitter, and applies the backup schedule.
            $this->moduleConfigService->applyPlan($site, $plan);
            $this->applyBackupConfigFromPlan($plan, $site);
        }

        if (in_array('security', $sections, true) && $plan->include_security && ! empty($plan->security_settings)) {
            $this->applySecuritySettings($plan->security_settings, $site);
            $needsSecurityPush = true;
        }

        if (in_array('tweaks', $sections, true) && $plan->include_tweaks && ! empty($plan->tweak_settings)) {
            $this->applyTweakSettings($plan->tweak_settings, $site);
            $needsTweaksPush = true;
        }

        $pushed = false;
        if ($site->is_connected) {
            if ($needsSecurityPush) {
                $this->securityService->pushToPlugin($site);
            }
            if ($needsTweaksPush) {
                $this->tweaksService->pushToPlugin($site);
            }
            $pushed = true;
        }

        return [
            'connected' => (bool) $site->is_connected,
            'pushed' => $pushed,
        ];
    }

    /**
     * The sections a plan is configured to carry, derived from its include_* flags.
     *
     * @return array<int, string>
     */
    private function planSections(MaintenancePlan $plan): array
    {
        $sections = [];

        if ($plan->include_modules) {
            $sections[] = 'modules';
        }
        if ($plan->include_security) {
            $sections[] = 'security';
        }
        if ($plan->include_tweaks) {
            $sections[] = 'tweaks';
        }

        return $sections;
    }

    // --- Fleet-apply progress (P1-60) ---------------------------------------

    /**
     * Cache key holding the progress record for a fleet apply batch.
     */
    public static function progressKey(string $batchId): string
    {
        return "plan-apply-progress:{$batchId}";
    }

    /**
     * Cache key holding the last failure reason for a per-site apply.
     */
    public static function failureKey(int $planId, int $siteId): string
    {
        return "plan-apply-failure:{$planId}:{$siteId}";
    }

    /**
     * Read the progress record for a fleet apply batch (or null once expired).
     *
     * @return array{total: int, done: int, failed: int, plan: string, complete: bool}|null
     */
    public static function progress(string $batchId): ?array
    {
        $record = Cache::get(self::progressKey($batchId));

        if (! is_array($record)) {
            return null;
        }

        $record['complete'] = ($record['done'] ?? 0) >= ($record['total'] ?? 0);

        return $record;
    }

    /**
     * Record a per-site apply outcome against a batch (best-effort progress).
     */
    public static function recordProgress(string $batchId, bool $failed): void
    {
        $key = self::progressKey($batchId);
        $record = Cache::get($key);

        if (! is_array($record)) {
            return;
        }

        $record['done'] = ($record['done'] ?? 0) + 1;
        if ($failed) {
            $record['failed'] = ($record['failed'] ?? 0) + 1;
        }

        Cache::put($key, $record, now()->addHours(6));
    }

    private function initProgress(string $batchId, int $total, string $planName): void
    {
        Cache::put(self::progressKey($batchId), [
            'total' => $total,
            'done' => 0,
            'failed' => 0,
            'plan' => $planName,
        ], now()->addHours(6));
    }

    /**
     * Snapshot security settings from a site into the plan JSON format.
     */
    private function snapshotSecuritySettings(Site $site): array
    {
        // Only bulk-safe categories/keys may be captured — never snapshot a
        // site's login URL, 2FA, CAPTCHA secret, or firewall config into a plan.
        $categories = array_keys(SecuritySettingsService::BULK_SAFE_SETTING_KEYS);

        /** @var \Illuminate\Database\Eloquent\Collection<int, SecuritySetting> $settings */
        $settings = $site->securitySettings()
            ->whereIn('category', $categories)
            ->get()
            ->filter(fn (SecuritySetting $s) => $this->securityService->isBulkSafeSetting( // @phpstan-ignore argument.type
                $s->category->value ?? $s->category,
                $s->setting_key,
            ));

        return $settings
            ->groupBy(fn (SecuritySetting $s) => $s->category->value ?? $s->category)
            ->map(fn (\Illuminate\Support\Collection $group) => $group->mapWithKeys(fn ($s) => [ // @phpstan-ignore argument.type, return.type
                $s->setting_key => [
                    'value' => $s->setting_value,
                    'enabled' => $s->is_enabled,
                ],
            ]))
            ->toArray();
    }

    /**
     * Snapshot tweak settings from a site into the plan JSON format.
     */
    private function snapshotTweakSettings(Site $site): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, SecuritySetting> $settings */
        $settings = $site->securitySettings()
            ->whereIn('category', SiteTweaksSettingsService::TWEAK_CATEGORIES)
            ->get();

        return $settings
            ->groupBy(fn (SecuritySetting $s) => $s->category->value ?? $s->category)
            ->map(fn (\Illuminate\Support\Collection $group) => $group->mapWithKeys(fn ($s) => [ // @phpstan-ignore argument.type, return.type
                $s->setting_key => [
                    'value' => $s->setting_value,
                    'enabled' => $s->is_enabled,
                ],
            ]))
            ->toArray();
    }

    /**
     * Snapshot module config from a site and create MaintenancePlanModule rows.
     */
    private function snapshotModules(Site $site, MaintenancePlan $plan): void
    {
        $config = $this->moduleConfigService->getConfig($site);

        foreach ($config as $moduleKey => $moduleConfig) {
            $moduleData = [
                'maintenance_plan_id' => $plan->id,
                'module_key' => $moduleKey,
                'is_enabled' => $moduleConfig['enabled'],
                'interval_minutes' => $moduleConfig['interval'],
            ];

            if ($moduleKey === 'backup') {
                $moduleData['config'] = $this->snapshotBackupConfig($site);
            }

            MaintenancePlanModule::create($moduleData);
        }
    }

    private function snapshotBackupConfig(Site $site): ?array
    {
        $backupConfig = $site->backupConfig;
        if (! $backupConfig) {
            return null;
        }

        return [
            'frequency' => $backupConfig->frequency,
            'time' => $backupConfig->time,
            'day_of_week' => $backupConfig->day_of_week,
            'day_of_month' => $backupConfig->day_of_month,
            'timezone' => $backupConfig->timezone,
            'type' => $backupConfig->type,
            'retention_type' => $backupConfig->retention_type,
            'retention_value' => $backupConfig->retention_value,
            'backup_before_updates' => $backupConfig->backup_before_updates,
        ];
    }

    /**
     * Apply security settings JSON to a site.
     */
    private function applySecuritySettings(array $settings, Site $site): void
    {
        // Filter on read: existing plans may still hold dangerous keys — never
        // overwrite the target site's login URL / 2FA / CAPTCHA secret / firewall.
        $settings = $this->securityService->filterBulkSafeSettings($settings);

        foreach ($settings as $category => $categorySettings) {
            foreach ($categorySettings as $key => $config) {
                SecuritySetting::updateOrCreate(
                    ['site_id' => $site->id, 'category' => $category, 'setting_key' => $key],
                    [
                        'setting_value' => $config['value'] ?? null,
                        'is_enabled' => $config['enabled'] ?? false,
                        'failed_at' => null,
                        'failure_reason' => null,
                    ],
                );
            }
        }
    }

    /**
     * Apply tweak settings JSON to a site.
     */
    private function applyTweakSettings(array $settings, Site $site): void
    {
        foreach ($settings as $category => $categorySettings) {
            foreach ($categorySettings as $key => $config) {
                $this->tweaksService->applySetting(
                    $site,
                    $category,
                    $key,
                    $config['value'] ?? null,
                    $config['enabled'] ?? false,
                );
            }
        }
    }

    /**
     * Save (create or update) a maintenance plan with its modules.
     */
    public function savePlan(
        array $planData,
        array $moduleConfigs,
        ?array $securitySettings,
        ?array $tweakSettings,
        ?int $editingId = null,
    ): MaintenancePlan {
        if ($planData['is_default'] ?? false) {
            MaintenancePlan::where('is_default', true)
                ->when($editingId, fn ($q) => $q->where('id', '!=', $editingId))
                ->update(['is_default' => false]);
        }

        $data = array_merge($planData, [
            'security_settings' => $securitySettings,
            'tweak_settings' => $tweakSettings,
        ]);

        if (! $editingId) {
            $data['created_by'] = auth()->id();
        }

        $plan = MaintenancePlan::updateOrCreate(
            ['id' => $editingId],
            $data,
        );

        if ($planData['include_modules'] ?? false) {
            foreach ($moduleConfigs as $key => $moduleData) {
                MaintenancePlanModule::updateOrCreate(
                    ['maintenance_plan_id' => $plan->id, 'module_key' => $key],
                    $moduleData,
                );
            }
        }

        // P2-36: editing a plan must propagate to the sites already using it,
        // otherwise their materialized module rows silently drift from the plan
        // (plan says X, sites still have the old Y). A fresh plan has no assigned
        // sites, so only an edit triggers the re-apply.
        if ($editingId) {
            $this->reapplyToAssignedSites($plan);
        }

        return $plan;
    }

    /**
     * Re-apply a plan to every site currently assigned to it (P2-36).
     *
     * Each site funnels through the canonical, queued {@see ApplyPlanToSite} job
     * so the edit propagates in the background (never blocking the save request).
     * The job is idempotent AND ShouldBeUnique per plan+site, so a re-apply can
     * neither stampede nor duplicate work; chunking keeps a huge fleet from being
     * loaded into memory all at once.
     */
    private function reapplyToAssignedSites(MaintenancePlan $plan): void
    {
        $plan->loadMissing('planModules');

        $plan->sites()->chunkById(200, function (Collection $sites) use ($plan): void {
            foreach ($sites as $site) {
                /** @var Site $site */
                ApplyPlanToSite::dispatch($site, $plan);
            }
        });
    }

    /**
     * Delete a maintenance plan if no sites are using it.
     *
     * @return array{success: bool, message: string}
     */
    public function deletePlan(int $planId): array
    {
        $plan = MaintenancePlan::withCount('sites')->findOrFail($planId);

        if ($plan->sites_count > 0) {
            return [
                'success' => false,
                'message' => "Cannot delete \"{$plan->name}\" — {$plan->sites_count} site(s) are using it.",
            ];
        }

        $plan->delete();

        return ['success' => true, 'message' => 'Plan deleted.'];
    }

    /**
     * Count total settings in a settings JSON blob.
     */
    public static function countSettings(?array $settings): int
    {
        if (! $settings) {
            return 0;
        }

        $count = 0;
        foreach ($settings as $categorySettings) {
            if (is_array($categorySettings)) {
                $count += count($categorySettings);
            }
        }

        return $count;
    }

    /**
     * Count enabled settings in a settings JSON blob.
     */
    public static function countEnabledSettings(?array $settings): int
    {
        if (! $settings) {
            return 0;
        }

        $count = 0;
        foreach ($settings as $categorySettings) {
            if (is_array($categorySettings)) {
                foreach ($categorySettings as $config) {
                    if ($config['enabled'] ?? false) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    private function applyBackupConfigFromPlan(MaintenancePlan $plan, Site $site): void
    {
        $backupModule = $plan->planModules->firstWhere('module_key', 'backup');
        if (! $backupModule || ! $backupModule->is_enabled || empty($backupModule->config)) {
            return;
        }

        $config = $backupModule->config;

        BackupConfig::updateOrCreate(
            ['site_id' => $site->id],
            [
                'frequency' => $config['frequency'] ?? 'daily',
                'time' => $config['time'] ?? '03:00',
                'day_of_week' => $config['day_of_week'] ?? 0,
                'day_of_month' => $config['day_of_month'] ?? 1,
                'timezone' => $config['timezone'] ?? 'Europe/Bucharest',
                'type' => $config['type'] ?? 'full',
                'retention_type' => $config['retention_type'] ?? 'count',
                'retention_value' => $config['retention_value'] ?? 10,
                'backup_before_updates' => $config['backup_before_updates'] ?? false,
            ],
        );
    }
}
