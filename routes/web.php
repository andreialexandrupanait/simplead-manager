<?php

use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\DropboxAuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\ReportDownloadController;
use App\Livewire\Backups;
use App\Livewire\Dashboard;
use App\Livewire\Performance;
use App\Livewire\Sites;
use App\Livewire\Uptime;
use App\Livewire\Clients;
use App\Livewire\Reports;
use App\Livewire\Settings;

// Auth routes (Breeze)
require __DIR__.'/auth.php';

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/', Dashboard\GlobalDashboard::class)->name('dashboard');

    // Sites — global
    Route::get('/sites', Sites\SitesList::class)->name('sites.index');
    Route::get('/sites/create', Sites\CreateSite::class)->name('sites.create');

    // Sites — site-context (uses {site} parameter)
    Route::prefix('/sites/{site}')->group(function () {
        Route::get('/', Sites\Detail\SiteOverview::class)->name('sites.overview');
        Route::get('/plugins', Sites\Detail\SitePlugins::class)->name('sites.plugins');
        Route::get('/updates', Sites\Detail\SiteUpdates::class)->name('sites.updates');
        Route::get('/security', Sites\Detail\SiteSecurity::class)->name('sites.security');
        Route::get('/performance', Sites\Detail\SitePerformance::class)->name('sites.performance');
        Route::get('/backups', Sites\Detail\SiteBackups::class)->name('sites.backups');
        Route::get('/uptime', Sites\Detail\SiteUptime::class)->name('sites.uptime');
        Route::get('/links', Sites\Detail\SiteLinks::class)->name('sites.links');
        Route::get('/analytics', Sites\Detail\SiteAnalytics::class)->name('sites.analytics');
        Route::get('/search-console', Sites\Detail\SiteSearchConsole::class)->name('sites.search-console');
        Route::get('/reports', Sites\Detail\SiteReports::class)->name('sites.reports');
        Route::get('/settings', Sites\Detail\SiteSettings::class)->name('sites.settings');
    });

    // Backups — global view
    Route::get('/backups', Backups\BackupsOverview::class)->name('backups.index');

    // Backup download (signed URL for local storage)
    Route::get('/backups/{backup}/download', BackupDownloadController::class)->name('backups.download')->middleware('signed');

    // Report download & preview
    Route::get('/reports/{report}/download', ReportDownloadController::class)->name('reports.download');

    // Performance — global view
    Route::get('/performance', Performance\PerformanceOverview::class)->name('performance.index');

    // Uptime — global view
    Route::get('/uptime', Uptime\UptimeOverview::class)->name('uptime.index');

    // Updates — global view
    Route::get('/updates', Dashboard\GlobalUpdates::class)->name('updates.index');

    // Activity — global view
    Route::get('/activity', Dashboard\GlobalActivity::class)->name('activity.index');

    // Clients
    Route::get('/clients', Clients\ClientsList::class)->name('clients.index');
    Route::get('/clients/{client}', Clients\ClientDetail::class)->name('clients.show');

    // Reports
    Route::get('/reports', Reports\ReportsOverview::class)->name('reports.index');

    // Plugin download
    Route::get('/download/connector-plugin', function () {
        $path = storage_path('app/simplead-connector.zip');
        abort_unless(file_exists($path), 404);
        return response()->download($path, 'simplead-connector.zip');
    })->name('download.connector-plugin');

    // Settings
    Route::prefix('/settings')->group(function () {
        Route::get('/', Settings\GeneralSettings::class)->name('settings.general');
        Route::get('/notifications', Settings\NotificationSettings::class)->name('settings.notifications');
        Route::get('/profile', Settings\ProfileSettings::class)->name('settings.profile');

        // Integrations
        Route::get('/integrations', Settings\IntegrationsSettings::class)->name('settings.integrations');

        // Report Templates
        Route::get('/report-templates', Settings\ReportTemplatesSettings::class)->name('settings.report-templates');

        // Dropbox OAuth
        Route::get('/storage/dropbox/auth', [DropboxAuthController::class, 'redirect'])->name('dropbox.auth');
        Route::get('/storage/dropbox/callback', [DropboxAuthController::class, 'callback'])->name('dropbox.callback');

        // Google OAuth
        Route::get('/google/auth', [GoogleAuthController::class, 'redirect'])->name('google.auth');
        Route::get('/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
    });
});
