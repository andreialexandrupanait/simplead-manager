<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class PluginInventoryGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'plugin_inventory';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $plugins = SitePlugin::where('site_id', $site->id)->get();
        $themes = SiteTheme::where('site_id', $site->id)->get();

        $activePlugins = $plugins->where('is_active', true)->count();
        $inactivePlugins = $plugins->where('is_active', false)->count();
        $withUpdates = $plugins->where('has_update', true)->count();
        $abandoned = $plugins->where('is_abandoned', true)->count();
        $closed = $plugins->where('is_closed', true)->count();

        $activeTheme = $themes->where('is_active', true)->first();

        $barData = $chartService->generateHorizontalBarData([
            ['value' => $activePlugins, 'label' => __('report.plugin_status_active', [], $language), 'color' => '#10b981'],
            ['value' => $inactivePlugins, 'label' => __('report.plugin_status_inactive', [], $language), 'color' => '#94a3b8'],
            ['value' => $withUpdates, 'label' => __('report.plugins_with_updates', [], $language), 'color' => '#f59e0b'],
        ]);

        return [
            'total_plugins' => $plugins->count(),
            'active_plugins' => $activePlugins,
            'inactive_plugins' => $inactivePlugins,
            'with_updates' => $withUpdates,
            'abandoned' => $abandoned,
            'closed' => $closed,
            'abandoned_or_closed' => $abandoned + $closed,
            'plugins' => $plugins->map(fn ($p) => [
                'name' => $p->name,
                'version' => $p->version,
                'is_active' => $p->is_active,
                'has_update' => $p->has_update,
                'update_version' => $p->update_version,
                'is_abandoned' => $p->is_abandoned,
                'is_closed' => $p->is_closed,
                'auto_update' => $p->auto_update,
            ])->sortByDesc('is_active')->values()->toArray(),
            'total_themes' => $themes->count(),
            'active_theme' => $activeTheme ? $activeTheme->name : null,
            'active_theme_version' => $activeTheme ? $activeTheme->version : null,
            'active_theme_is_child' => $activeTheme ? $activeTheme->is_child_theme : false,
            'active_theme_parent' => $activeTheme ? $activeTheme->parent_theme : null,
            'themes_with_updates' => $themes->where('has_update', true)->count(),
            'themes' => $themes->map(fn ($t) => [
                'name' => $t->name,
                'version' => $t->version,
                'is_active' => $t->is_active,
                'has_update' => $t->has_update,
                'update_version' => $t->update_version,
                'is_child_theme' => $t->is_child_theme,
                'parent_theme' => $t->parent_theme,
            ])->sortByDesc('is_active')->values()->toArray(),
            'horizontal_bar_chart' => $barData,
        ];
    }
}
