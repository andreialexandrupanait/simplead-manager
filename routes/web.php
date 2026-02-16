<?php

use App\Http\Controllers\AppBackupDownloadController;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\DropboxAuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\ReportDownloadController;
use App\Livewire\Backups;
use App\Livewire\Dashboard;
use App\Livewire\Performance;
use App\Livewire\Sites;
use App\Livewire\Uptime;
use App\Livewire\Clients;
use App\Livewire\Reports;
use App\Livewire\Settings;
use App\Livewire\StatusPages;

// Health check (no auth)
Route::get('/health', HealthCheckController::class)->middleware('throttle:30,1');

// Temporary restore file download (token-protected, no auth)
Route::get('/restore-download/{token}', function (string $token) {
    // Only allow hex tokens (64 chars)
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        abort(404);
    }
    $path = storage_path("app/temp/restore-{$token}");
    if (!file_exists($path)) {
        abort(404);
    }
    return response()->file($path);
})->middleware('throttle:10,1');

// Report download via signed URL (for client emails — no auth required)
Route::get('/reports/{report}/download/signed', ReportDownloadController::class)
    ->name('reports.download.signed')
    ->middleware(['signed', 'throttle:30,1']);

// Auth routes (Breeze)
require __DIR__.'/auth.php';

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/', Dashboard\GlobalDashboard::class)->name('dashboard');
    Route::get('/dashboard/widgets', fn () => redirect()->route('dashboard'))->name('dashboard.widgets');

    // Sites — global (redirect to dashboard)
    Route::redirect('/sites', '/')->name('sites.index');
    Route::get('/sites/create', Sites\CreateSiteWizard::class)->name('sites.create');

    // Sites — site-context (uses {site} parameter)
    Route::prefix('/sites/{site}')->group(function () {
        Route::get('/', Sites\Detail\SiteOverview::class)->name('sites.overview');
        Route::get('/plugins', Sites\Detail\SitePlugins::class)->name('sites.plugins');
        Route::get('/security', Sites\Detail\SiteSecurity::class)->name('sites.security');
        Route::get('/performance', Sites\Detail\SitePerformance::class)->name('sites.performance');
        Route::get('/backups', Sites\Detail\SiteBackups::class)->name('sites.backups');
        Route::get('/uptime', Sites\Detail\SiteUptime::class)->name('sites.uptime');
        Route::get('/analytics', Sites\Detail\SiteAnalytics::class)->name('sites.analytics');
        Route::get('/search-console', Sites\Detail\SiteSearchConsole::class)->name('sites.search-console');
        Route::get('/cloudflare', Sites\Detail\SiteCloudflare::class)->name('sites.cloudflare');
        Route::get('/database', Sites\Detail\SiteDatabaseCleanup::class)->name('sites.database');
        Route::get('/reports', Sites\Detail\SiteReports::class)->name('sites.reports');
        Route::get('/settings', Sites\Detail\SiteSettings::class)->name('sites.settings');

    });

    // Backups — global view
    Route::get('/backups', Backups\BackupsOverview::class)->name('backups.index');

    // Backup download (signed URL for local storage)
    Route::get('/backups/{backup}/download', BackupDownloadController::class)->name('backups.download')->middleware('signed');

    // Report download & preview (authenticated users)
    Route::get('/reports/{report}/download', ReportDownloadController::class)->name('reports.download');

    // Performance — global view
    Route::get('/performance', Performance\PerformanceOverview::class)->name('performance.index');

    // Uptime — global view
    Route::get('/uptime', Uptime\UptimeOverview::class)->name('uptime.index');


    // Clients
    Route::prefix('clients')->group(function () {
        Route::get('/', Clients\ClientsList::class)->name('clients.index');
        Route::get('/create', Clients\ClientForm::class)->name('clients.create');
        Route::get('/{client}', Clients\ClientDetail::class)->name('clients.show');
        Route::get('/{client}/edit', Clients\ClientForm::class)->name('clients.edit');
    });

    // Reports
    Route::get('/reports', Reports\ReportsOverview::class)->name('reports.index');


    // Plugin download
    Route::get('/download/connector-plugin', function () {
        $path = public_path('simplead-manager-connector.zip');
        abort_unless(file_exists($path), 404);
        return response()->download($path, 'simplead-connector.zip');
    })->name('download.connector-plugin');

    // Settings — Profile accessible to all roles
    Route::prefix('/settings')->group(function () {
        Route::get('/profile', Settings\ProfileSettings::class)->name('settings.profile');
    });

    // Settings — Admin-only pages
    Route::prefix('/settings')->middleware('role:admin')->group(function () {
        Route::get('/', Settings\GeneralSettings::class)->name('settings.general');
        Route::get('/notifications', Settings\NotificationSettings::class)->name('settings.notifications');

        // Integrations
        Route::get('/integrations', Settings\IntegrationsSettings::class)->name('settings.integrations');

        // Report Templates
        Route::get('/report-templates', Settings\ReportTemplatesSettings::class)->name('settings.report-templates');

        // Application Backup
        Route::get('/application-backup', Settings\ApplicationBackup::class)->name('settings.application-backup');

        // Site Presets
        Route::get('/site-presets', Settings\SitePresetsSettings::class)->name('settings.site-presets');

        // Status Pages
        Route::get('/status-pages', StatusPages\StatusPagesList::class)->name('settings.status-pages');
        Route::get('/status-pages/create', StatusPages\StatusPageEdit::class)->name('settings.status-pages.create');
        Route::get('/status-pages/{statusPage}/edit', StatusPages\StatusPageEdit::class)->name('settings.status-pages.edit');

        // App backup download (signed URL for local storage)
        Route::get('/app-backups/{appBackup}/download', AppBackupDownloadController::class)->name('app-backups.download')->middleware('signed');

        // Dropbox OAuth
        Route::get('/storage/dropbox/auth', [DropboxAuthController::class, 'redirect'])->name('dropbox.auth');
        Route::get('/storage/dropbox/callback', [DropboxAuthController::class, 'callback'])->name('dropbox.callback');

        // Google OAuth
        Route::get('/google/auth', [GoogleAuthController::class, 'redirect'])->name('google.auth');
        Route::get('/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
    });
});

// Public status pages (no auth)
Route::get('/status/{slug}', [\App\Http\Controllers\StatusPageController::class, '__invoke'])->name('status-page.show')->middleware('throttle:status-page');
Route::post('/status/{slug}/auth', [\App\Http\Controllers\StatusPageController::class, 'authenticate'])->name('status-page.auth')->middleware('throttle:login');
Route::get('/api/status/{slug}', [\App\Http\Controllers\StatusPageController::class, 'api'])->name('status-page.api')->middleware('throttle:status-page');
