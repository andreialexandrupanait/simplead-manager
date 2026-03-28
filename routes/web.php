<?php

use App\Http\Controllers\AppBackupDownloadController;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\BulkReportDownloadController;
use App\Http\Controllers\ConnectorPluginDownloadController;
use App\Http\Controllers\DropboxAuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\ReportDownloadController;
use App\Livewire\Backups;
use App\Livewire\Clients;
use App\Livewire\Dashboard;
use App\Livewire\MaintenancePlans;
use App\Livewire\Performance;
use App\Livewire\Reports;
use App\Livewire\Security;
use App\Livewire\Settings;
use App\Livewire\Sites;
use App\Livewire\StatusPages;
use App\Livewire\Uptime;
use App\Models\Site;

// Health check (no auth)
Route::get('/health', HealthCheckController::class)->middleware('throttle:30,1');

// Temporary restore file download (token-protected, no auth)
Route::get('/restore-download/{token}', function (string $token) {
    // Only allow hex tokens (64 chars)
    if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
        abort(404);
    }
    $path = storage_path("app/temp/restore-{$token}");
    if (! file_exists($path)) {
        abort(404);
    }

    return response()->file($path);
})->middleware('throttle:10,1');

// Report download via signed URL (for client emails — no auth required)
Route::get('/reports/{report}/download/signed', ReportDownloadController::class)
    ->name('reports.download.signed')
    ->middleware(['signed', 'throttle:30,1']);

// Plugin download via signed URL (for WP self-update — no auth required)
Route::get('/download/connector-plugin/signed', ConnectorPluginDownloadController::class)
    ->name('download.connector-plugin.signed')
    ->middleware(['signed', 'throttle:10,1']);

// Auth routes (Breeze)
require __DIR__.'/auth.php';

// Authenticated routes
Route::middleware(['auth', 'verified', 'throttle:authenticated'])->group(function () {

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
        Route::get('/security', Sites\Detail\Security\SecurityOverview::class)->name('sites.security');
        Route::get('/security/hardening', Sites\Detail\Security\SecurityHardening::class)->name('sites.security.hardening');
        Route::get('/security/login', Sites\Detail\Security\SecurityLogin::class)->name('sites.security.login');
        Route::get('/security/captcha', Sites\Detail\Security\SecurityCaptcha::class)->name('sites.security.captcha');
        Route::get('/security/scanning', Sites\Detail\Security\SecurityScanning::class)->name('sites.security.scanning');
        Route::get('/security/activity', Sites\Detail\Security\SecurityActivity::class)->name('sites.security.activity');
        Route::get('/security/users', Sites\Detail\Security\SecurityUsers::class)->name('sites.security.users');
        Route::get('/security/ip-management', Sites\Detail\Security\SecurityIpManagement::class)->name('sites.security.ip-management');
        Route::get('/security/performance', Sites\Detail\Tweaks\TweaksPerformance::class)->name('sites.security.performance');
        Route::get('/security/site-control', Sites\Detail\Tweaks\TweaksSiteControl::class)->name('sites.security.site-control');
        Route::get('/security/admin-ux', Sites\Detail\Security\SecurityComingSoon::class)->name('sites.security.admin-ux');
        Route::get('/security/content-media', Sites\Detail\Security\SecurityComingSoon::class)->name('sites.security.content-media');
        Route::get('/security/email', Sites\Detail\Security\SecurityComingSoon::class)->name('sites.security.email');
        Route::get('/tweaks', fn (Site $site) => redirect()->route('sites.security.performance', $site))->name('sites.tweaks');
        Route::get('/tweaks/performance', fn (Site $site) => redirect()->route('sites.security.performance', $site))->name('sites.tweaks.performance');
        Route::get('/tweaks/site-control', fn (Site $site) => redirect()->route('sites.security.site-control', $site))->name('sites.tweaks.site-control');
        Route::get('/performance', Sites\Detail\SitePerformance::class)->name('sites.performance');
        Route::get('/backups', Sites\Detail\SiteBackups::class)->name('sites.backups');
        Route::get('/uptime', Sites\Detail\SiteUptime::class)->name('sites.uptime');
        Route::get('/analytics', Sites\Detail\SiteAnalytics::class)->name('sites.analytics');
        Route::get('/search-console', Sites\Detail\SiteSearchConsole::class)->name('sites.search-console');
        Route::get('/cloudflare', Sites\Detail\SiteCloudflare::class)->name('sites.cloudflare');
        Route::get('/database', Sites\Detail\SiteDatabaseCleanup::class)->name('sites.database');
        Route::get('/cron', Sites\Detail\SiteCron::class)->name('sites.cron');
        Route::get('/reports', Sites\Detail\SiteReports::class)->name('sites.reports');
        Route::get('/reports/bulk-download', BulkReportDownloadController::class)->name('reports.bulk-download')->middleware('throttle:10,1');
        Route::get('/settings', Sites\Detail\SiteSettings::class)->name('sites.settings');

    });

    // Maintenance Plans
    Route::get('/maintenance-plans', MaintenancePlans::class)->name('maintenance-plans');
    Route::redirect('/site-presets', '/maintenance-plans');
    Route::redirect('/bulk-settings', '/maintenance-plans');

    // Backups — global view
    Route::get('/backups', Backups\BackupsOverview::class)->name('backups.index');

    // Backup download (signed URL for local storage)
    Route::get('/backups/{backup}/download', BackupDownloadController::class)->name('backups.download')->middleware(['signed', 'throttle:10,1']);

    // Report download & preview (authenticated users)
    Route::get('/reports/{report}/download', ReportDownloadController::class)->name('reports.download')->middleware('throttle:30,1');

    // Performance — global view
    Route::get('/performance', Performance\PerformanceOverview::class)->name('performance.index');

    // Security — global views
    Route::get('/security', Security\SecurityDashboard::class)->name('security.index');
    Route::get('/security/presets', Security\PresetManager::class)->name('security.presets')->middleware('role:admin');

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

    // Plugin download (generated on-the-fly from source)
    Route::get('/download/connector-plugin', ConnectorPluginDownloadController::class)
        ->name('download.connector-plugin');

    // Settings — Profile accessible to all roles
    Route::prefix('/settings')->group(function () {
        Route::get('/profile', Settings\ProfileSettings::class)->name('settings.profile');
    });

    // Settings — Admin-only pages
    Route::prefix('/settings')->middleware('role:admin')->group(function () {
        Route::get('/', Settings\GeneralSettings::class)->name('settings.general');
        Route::get('/notifications', Settings\NotificationSettings::class)->name('settings.notifications');
        Route::get('/email', Settings\EmailSettings::class)->name('settings.email');

        // Integrations
        Route::get('/integrations', Settings\IntegrationsSettings::class)->name('settings.integrations');

        // Report Templates
        Route::get('/report-templates', Settings\ReportTemplatesSettings::class)->name('settings.report-templates');

        // Data Retention
        Route::get('/data-retention', Settings\DataRetentionSettings::class)->name('settings.data-retention');

        // Application Backup
        Route::get('/application-backup', Settings\ApplicationBackup::class)->name('settings.application-backup');

        // Users & Invitations
        Route::get('/users', Settings\UserManagement::class)->name('settings.users');

        // Maintenance Plans (redirect to standalone page)
        Route::redirect('/site-presets', '/maintenance-plans')->name('settings.maintenance-plans');

        // Status Pages
        Route::get('/status-pages', StatusPages\StatusPagesList::class)->name('settings.status-pages');
        Route::get('/status-pages/create', StatusPages\StatusPageEdit::class)->name('settings.status-pages.create');
        Route::get('/status-pages/{statusPage}/edit', StatusPages\StatusPageEdit::class)->name('settings.status-pages.edit');

        // App backup download (signed URL for local storage)
        Route::get('/app-backups/{appBackup}/download', AppBackupDownloadController::class)->name('app-backups.download')->middleware(['signed', 'throttle:10,1']);

        // Dropbox OAuth
        Route::get('/storage/dropbox/auth', [DropboxAuthController::class, 'redirect'])->name('dropbox.auth')->middleware('throttle:10,1');
        Route::get('/storage/dropbox/callback', [DropboxAuthController::class, 'callback'])->name('dropbox.callback')->middleware('throttle:10,1');

        // Google OAuth
        Route::get('/google/auth', [GoogleAuthController::class, 'redirect'])->name('google.auth')->middleware('throttle:10,1');
        Route::get('/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback')->middleware('throttle:10,1');
    });
});

// Google SSO
Route::get('/auth/google', [\App\Http\Controllers\Auth\GoogleSsoController::class, 'redirect'])->name('auth.google')->middleware('guest');
Route::get('/auth/google/callback', [\App\Http\Controllers\Auth\GoogleSsoController::class, 'callback'])->name('auth.google.callback')->middleware('guest');

// Public status pages (no auth)
// Notification acknowledgment (public, token-based)
Route::get('/notifications/ack/{token}', \App\Http\Controllers\NotificationAckController::class)->name('notifications.ack')->middleware('throttle:30,1');

// Client Portal (public, token-based)
Route::get('/portal/{token}', [\App\Http\Controllers\ClientPortalController::class, 'show'])->name('client-portal.show')->middleware('throttle:60,1');
Route::get('/portal/{token}/reports/{report}/download', [\App\Http\Controllers\ClientPortalController::class, 'downloadReport'])->name('client-portal.download')->middleware('throttle:10,1');

// Invitation accept (public)
Route::get('/invitation/{token}', [\App\Http\Controllers\Auth\AcceptInvitationController::class, 'show'])->name('invitation.accept');
Route::post('/invitation/{token}', [\App\Http\Controllers\Auth\AcceptInvitationController::class, 'store'])->middleware('throttle:10,1');

Route::get('/status/{slug}', [\App\Http\Controllers\StatusPageController::class, '__invoke'])->name('status-page.show')->middleware('throttle:status-page');
Route::post('/status/{slug}/auth', [\App\Http\Controllers\StatusPageController::class, 'authenticate'])->name('status-page.auth')->middleware('throttle:status-page-auth');
Route::get('/api/status/{slug}', [\App\Http\Controllers\StatusPageController::class, 'api'])->name('status-page.api')->middleware('throttle:status-page');
Route::get('/status/{slug}/badge.svg', [\App\Http\Controllers\StatusPageController::class, 'badge'])->name('status-page.badge')->middleware('throttle:status-page');
