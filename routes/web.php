<?php

use App\Http\Controllers\AppBackupDownloadController;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\BulkReportDownloadController;
use App\Http\Controllers\ConnectorPluginDownloadController;
use App\Http\Controllers\DropboxAuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\ReportDownloadController;
use App\Http\Controllers\ReportViewController;
use App\Http\Controllers\WebhookController;
use App\Livewire\Activity;
use App\Livewire\Backups;
use App\Livewire\Clients;
use App\Livewire\Dashboard;
use App\Livewire\Dns;
use App\Livewire\ErrorLogs;
use App\Livewire\MaintenancePlans;
use App\Livewire\Notifications;
use App\Livewire\Performance;
use App\Livewire\Reports;
use App\Livewire\Security;
use App\Livewire\Settings;
use App\Livewire\Sites;
use App\Livewire\Updates;
use App\Livewire\Uptime;
use App\Models\Site;

// Health check (no auth)
Route::get('/health', HealthCheckController::class)->middleware('throttle:30,1');

// Inbound webhooks (no auth, rate-limited)
Route::post('/api/webhooks/inbound', [WebhookController::class, 'handle'])->name('webhooks.inbound')->middleware('throttle:60,1');

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

    // The legitimate window is the single connector fetch inside the restore
    // POST (≤30 min). A worker killed between staging and cleanup used to
    // leave the token downloadable until the 24h temp sweep — expire it here.
    $mtime = filemtime($path);
    if ($mtime === false || $mtime < now()->subMinutes(45)->getTimestamp()) {
        @unlink($path);
        abort(404);
    }

    return response()->file($path);
})->middleware('throttle:10,1');

// Report download via signed URL (for client emails — no auth required)
Route::get('/reports/{report}/download/signed', ReportDownloadController::class)
    ->name('reports.download.signed')
    ->middleware(['signed', 'throttle:30,1']);

// Permanent report view link (token-protected, no auth)
Route::get('/r/{report}/{token}', ReportViewController::class)
    ->name('reports.view.public')
    ->middleware('throttle:60,1');

// Plugin download via signed URL (for WP self-update — no auth required)
Route::get('/download/connector-plugin/signed', ConnectorPluginDownloadController::class)
    ->name('download.connector-plugin.signed')
    ->middleware(['signed', 'throttle:10,1']);

// Auth routes (Breeze)
require __DIR__.'/auth.php';

// Two-factor challenge (authenticated but NOT behind the 2FA gate, so a user
// mid-login can reach it). C-02.
Route::middleware(['auth'])->group(function () {
    Route::get('/two-factor-challenge', \App\Livewire\Auth\TwoFactorChallenge::class)
        ->name('two-factor.challenge')
        ->middleware('throttle:authenticated');
});

// Authenticated routes — gated behind the 2FA challenge + admin enrollment.
Route::middleware(['auth', 'verified', 'throttle:authenticated', '2fa.challenge', '2fa.enforce'])->group(function () {

    // Main screen (SPEC §4): the WPMU-Hub site list + the three-band Panou.
    Route::get('/', Sites\SitesList::class)->name('dashboard');
    Route::get('/dashboard/widgets', fn () => redirect()->route('dashboard'))->name('dashboard.widgets');
    // Classic widget dashboard — kept as a backup view (the pre-§4 landing).
    Route::get('/dashboard/classic', Dashboard\GlobalDashboard::class)->name('dashboard.classic');

    // Sites — global list (same §4 screen).
    Route::get('/sites', Sites\SitesList::class)->name('sites.index');
    Route::get('/sites/create', Sites\CreateSiteWizard::class)->name('sites.create');

    // Sites — site-context (uses {site} parameter)
    Route::prefix('/sites/{site}')->group(function () {
        Route::get('/', Sites\Detail\SiteOverview::class)->name('sites.overview');
        Route::get('/plugins', Sites\Detail\SitePlugins::class)->name('sites.plugins');
        Route::get('/redirects', Sites\Detail\SiteRedirects::class)->name('sites.redirects');
        Route::get('/security', Sites\Detail\Security\SecurityOverview::class)->name('sites.security');
        Route::get('/security/hardening', Sites\Detail\Security\SecurityHardening::class)->name('sites.security.hardening');
        Route::get('/security/login', Sites\Detail\Security\SecurityLogin::class)->name('sites.security.login');
        Route::get('/security/captcha', Sites\Detail\Security\SecurityCaptcha::class)->name('sites.security.captcha');
        Route::get('/security/scanning', Sites\Detail\Security\SecurityScanning::class)->name('sites.security.scanning');
        Route::get('/security/activity', Sites\Detail\Security\SecurityActivity::class)->name('sites.security.activity');
        Route::get('/security/users', Sites\Detail\Security\SecurityUsers::class)->name('sites.security.users');
        Route::get('/security/ip-management', Sites\Detail\Security\SecurityIpManagement::class)->name('sites.security.ip-management');
        // Tweaks merged into the Security & Tweaks hub — keep the name for old links
        Route::get('/tweaks', fn (Site $site) => redirect()->route('sites.security', $site))->name('sites.tweaks');
        Route::get('/tweaks/performance', Sites\Detail\Tweaks\TweaksPerformance::class)->name('sites.tweaks.performance');
        Route::get('/tweaks/site-control', Sites\Detail\Tweaks\TweaksSiteControl::class)->name('sites.tweaks.site-control');
        Route::get('/tweaks/admin-ux', Sites\Detail\Tweaks\TweaksAdminUx::class)->name('sites.tweaks.admin-ux');
        Route::get('/tweaks/content-media', Sites\Detail\Tweaks\TweaksContentMedia::class)->name('sites.tweaks.content-media');
        // Backward-compat redirects from old security URLs
        Route::get('/security/performance', fn (Site $site) => redirect()->route('sites.tweaks.performance', $site));
        Route::get('/security/site-control', fn (Site $site) => redirect()->route('sites.tweaks.site-control', $site));
        Route::get('/security/admin-ux', fn (Site $site) => redirect()->route('sites.tweaks.admin-ux', $site));
        Route::get('/security/content-media', fn (Site $site) => redirect()->route('sites.tweaks.content-media', $site));
        Route::get('/performance', Sites\Detail\SitePerformance::class)->name('sites.performance');
        Route::get('/backups', Sites\Detail\SiteBackups::class)->name('sites.backups');
        Route::get('/uptime', Sites\Detail\SiteUptime::class)->name('sites.uptime');
        Route::get('/analytics', Sites\Detail\SiteAnalytics::class)->name('sites.analytics');
        Route::get('/search-console', Sites\Detail\SiteSearchConsole::class)->name('sites.search-console');
        Route::get('/cloudflare', Sites\Detail\SiteCloudflare::class)->name('sites.cloudflare');
        Route::get('/database', Sites\Detail\SiteDatabaseCleanup::class)->name('sites.database');
        Route::get('/cron', Sites\Detail\SiteCron::class)->name('sites.cron');
        Route::get('/reports', Sites\Detail\SiteReports::class)->name('sites.reports');
        Route::get('/reports/{report}/view', Sites\Detail\ReportView::class)->name('sites.reports.view');
        Route::get('/reports/bulk-download', BulkReportDownloadController::class)->name('reports.bulk-download')->middleware('throttle:10,1');
        Route::get('/settings', Sites\Detail\SiteSettings::class)->name('sites.settings');

    });

    // Maintenance Plans
    Route::get('/maintenance-plans', MaintenancePlans::class)->name('maintenance-plans');
    Route::redirect('/site-presets', '/maintenance-plans');
    Route::redirect('/bulk-settings', '/maintenance-plans');

    // Backups — global view
    Route::get('/backups', Backups\BackupsOverview::class)->name('backups.index');

    // Backup engine V2 console (P5) — flag-gated + admin-only, isolated from the
    // V1 backup UI. With config('backup_v2.ui_enabled') false every route 404s, so
    // the console is invisible in production with the default flags.
    Route::prefix('/backup-v2')->middleware('backup-v2.ui')->group(function () {
        Route::get('/', \App\Livewire\Backup\V2\BackupV2Overview::class)->name('backup-v2.index');
        Route::get('/sites/{site}', \App\Livewire\Backup\V2\SiteBackupV2::class)->name('backup-v2.site');
        Route::get('/sessions/{session}', \App\Livewire\Backup\V2\BackupV2Detail::class)->name('backup-v2.detail');
    });

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

    // Updates — global view
    Route::get('/updates', Updates\UpdatesOverview::class)->name('updates.index');

    // Activity — global timeline
    Route::get('/activity', Activity\ActivityTimeline::class)->name('activity.index');

    // Error Logs — global view
    Route::get('/error-logs', ErrorLogs\ErrorLogsOverview::class)->name('error-logs.index');

    // DNS — global view
    Route::get('/dns', Dns\DnsOverview::class)->name('dns.index');

    // Notification Center
    Route::get('/notifications', Notifications\NotificationCenter::class)->name('notifications.index');

    // User preferences API
    Route::post('/api/user/theme', function () {
        auth()->user()->update(['theme' => request('theme') === 'dark' ? 'dark' : 'light']);

        return response()->json(['ok' => true]);
    })->name('api.user.theme');

    // Clients
    Route::prefix('clients')->group(function () {
        Route::get('/', Clients\ClientsList::class)->name('clients.index');
        Route::get('/{client}', Clients\ClientDetail::class)->name('clients.show');
    });

    // Reports
    Route::get('/reports', Reports\ReportsOverview::class)->name('reports.index');

    // Plugin download (generated on-the-fly from source)
    Route::get('/download/connector-plugin', ConnectorPluginDownloadController::class)
        ->name('download.connector-plugin');

    // Settings — Profile & 2FA accessible to all roles
    Route::prefix('/settings')->group(function () {
        Route::get('/profile', Settings\ProfileSettings::class)->name('settings.profile');
        Route::get('/two-factor', Settings\TwoFactorAuthentication::class)->name('settings.two-factor');
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

        // WordPress
        Route::get('/wordpress', Settings\WordPressSettings::class)->name('settings.wordpress');

        // Application Backup
        Route::get('/application-backup', Settings\ApplicationBackup::class)->name('settings.application-backup');

        // Maintenance Plans (redirect to standalone page)
        Route::redirect('/site-presets', '/maintenance-plans')->name('settings.maintenance-plans');

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

// Notification acknowledgment (public, token-based).
// P1-23: GET only shows a confirm page (crawler-safe); POST mutates.
Route::get('/notifications/ack/{token}', [\App\Http\Controllers\NotificationAckController::class, 'show'])->name('notifications.ack')->middleware('throttle:30,1');
Route::post('/notifications/ack/{token}', [\App\Http\Controllers\NotificationAckController::class, 'confirm'])->name('notifications.ack.confirm')->middleware('throttle:30,1');
