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
use App\Livewire\Backups;
use App\Livewire\Clients;
use App\Livewire\Dashboard;
use App\Livewire\MaintenancePlans;
use App\Livewire\Performance;
use App\Livewire\Reports;
use App\Livewire\Security;
use App\Livewire\Settings;
use App\Livewire\Sites;
use App\Livewire\Seo;
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

// Authenticated routes
Route::middleware(['auth', 'verified', 'throttle:authenticated'])->group(function () {

    // Dashboard
    Route::get('/', Dashboard\GlobalDashboard::class)->name('dashboard');
    Route::get('/dashboard/widgets', fn () => redirect()->route('dashboard'))->name('dashboard.widgets');

    // Sites — global list (redirects to dashboard)
    Route::get('/sites', fn () => redirect()->route('dashboard'))->name('sites.index');
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
        Route::get('/tweaks', Sites\Detail\Tweaks\TweaksOverview::class)->name('sites.tweaks');
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
        Route::get('/seo', Sites\Detail\Seo\SeoOverview::class)->name('sites.seo');
        Route::get('/seo/audit', Sites\Detail\Seo\SeoAuditResults::class)->name('sites.seo.audit');
        Route::get('/seo/keywords', Sites\Detail\Seo\SeoKeywords::class)->name('sites.seo.keywords');
        Route::get('/seo/technical', Sites\Detail\Seo\SeoTechnical::class)->name('sites.seo.technical');
        Route::get('/seo/performance', Sites\Detail\Seo\SeoCoreWebVitals::class)->name('sites.seo.performance');
        Route::get('/seo/backlinks', Sites\Detail\Seo\SeoBacklinks::class)->name('sites.seo.backlinks');
        Route::get('/seo/crawl', Sites\Detail\Seo\SeoCrawl::class)->name('sites.seo.crawl');
        Route::get('/seo/crawl/results', Sites\Detail\Seo\SeoCrawlResults::class)->name('sites.seo.crawl.results');
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

    // Backup download (signed URL for local storage)
    Route::get('/backups/{backup}/download', BackupDownloadController::class)->name('backups.download')->middleware(['signed', 'throttle:10,1']);

    // Report download & preview (authenticated users)
    Route::get('/reports/{report}/download', ReportDownloadController::class)->name('reports.download')->middleware('throttle:30,1');

    // Performance — global view
    Route::get('/performance', Performance\PerformanceOverview::class)->name('performance.index');

    // Security — global views
    Route::get('/security', Security\SecurityDashboard::class)->name('security.index');
    Route::get('/security/presets', Security\PresetManager::class)->name('security.presets')->middleware('role:admin');

    // SEO — global views
    Route::prefix('/seo')->group(function () {
        Route::get('/', Seo\SeoDashboard::class)->name('seo.index');
        Route::get('/content', Seo\ContentIndex::class)->name('seo.content.index');
        Route::get('/content/create', Seo\ContentEditor::class)->name('seo.content.create');
        Route::get('/content/{seoContent}/edit', Seo\ContentEditor::class)->name('seo.content.edit');
        Route::get('/keywords', Seo\KeywordResearch::class)->name('seo.keywords.index');
        Route::get('/calendar', Seo\ContentCalendar::class)->name('seo.calendar');
        Route::get('/backlinks', Seo\SeoBacklinks::class)->name('seo.backlinks');
        Route::get('/alerts', Seo\SeoAlerts::class)->name('seo.alerts');
    });

    // Crawler — separate module
    Route::prefix('/crawler')->group(function () {
        Route::get('/', Seo\CrawlerIndex::class)->name('crawler.index');
        Route::get('/create', Seo\CrawlerCreate::class)->name('crawler.create');
        Route::get('/{siteCrawl}', Seo\CrawlerResults::class)->name('crawler.show');
        Route::get('/{siteCrawl}/compare/{compareTo}', Seo\CrawlerComparison::class)->name('crawler.compare');
    });

    // Uptime — global view
    Route::get('/uptime', Uptime\UptimeOverview::class)->name('uptime.index');

    // User preferences API
    Route::post('/api/user/theme', function () {
        auth()->user()->update(['theme' => request('theme') === 'dark' ? 'dark' : 'light']);

        return response()->json(['ok' => true]);
    })->name('api.user.theme');

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

        // WordPress
        Route::get('/wordpress', Settings\WordPressSettings::class)->name('settings.wordpress');

        // AI Incident Response
        Route::get('/ai-incident-response', Settings\AiIncidentResponseSettings::class)->name('settings.ai-incident-response');

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
Route::get('/portal/{token}/reports/{report}', [\App\Http\Controllers\ClientPortalController::class, 'viewReport'])->name('client-portal.report')->middleware('throttle:60,1');
Route::get('/portal/{token}/reports/{report}/download', [\App\Http\Controllers\ClientPortalController::class, 'downloadReport'])->name('client-portal.download')->middleware('throttle:10,1');

// Invitation accept (public)
Route::get('/invitation/{token}', [\App\Http\Controllers\Auth\AcceptInvitationController::class, 'show'])->name('invitation.accept');
Route::post('/invitation/{token}', [\App\Http\Controllers\Auth\AcceptInvitationController::class, 'store'])->middleware('throttle:10,1');

Route::get('/status/{slug}', [\App\Http\Controllers\StatusPageController::class, '__invoke'])->name('status-page.show')->middleware('throttle:status-page');
Route::post('/status/{slug}/auth', [\App\Http\Controllers\StatusPageController::class, 'authenticate'])->name('status-page.auth')->middleware('throttle:status-page-auth');
Route::get('/api/status/{slug}', [\App\Http\Controllers\StatusPageController::class, 'api'])->name('status-page.api')->middleware('throttle:status-page');
Route::get('/status/{slug}/badge.svg', [\App\Http\Controllers\StatusPageController::class, 'badge'])->name('status-page.badge')->middleware('throttle:status-page');
