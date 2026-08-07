<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Backup\V2\Models\BackupSession;
use App\Mail\Backup\V2\BackupV2SuccessMail;
use App\Mail\Backup\V2\StorageQuotaReachedMail;
use App\Mail\BackupAlertMail;
use App\Mail\BudgetViolationMail;
use App\Mail\DailyDigestMail;
use App\Mail\PerformanceAlertMail;
use App\Mail\ReportGeneratedMail;
use App\Models\Backup;
use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use App\Models\Report;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every transactional email shares one layout and one translation convention.
 * These are the two things that silently rot: a template gets copied, keeps its
 * own CSS, and drifts; or a string is added without a Romanian counterpart and
 * only shows up in English months later.
 */
class TransactionalMailTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function mailables(): array
    {
        return [
            'backup failed' => ['backupFailed'],
            'backup completed' => ['backupCompleted'],
            'storage quota' => ['storageQuota'],
            'performance drop' => ['performanceDrop'],
            'budget violation' => ['budgetViolation'],
            'daily digest' => ['dailyDigest'],
            'report generated' => ['reportGenerated'],
        ];
    }

    #[DataProvider('mailables')]
    public function test_it_renders_on_the_shared_layout(string $factory): void
    {
        $html = $this->{$factory}()->render();

        // The brand band and the sign-off both live in x-mail.layout, so their
        // presence is proof the template goes through it rather than carrying
        // its own document.
        $this->assertStringContainsString('background-color:#1f2340', $html);
        $this->assertMatchesRegularExpression('/Cheers, the .+ team|Cu bine, echipa/', $html);

        // The markers of the old copy-pasted documents.
        $this->assertStringNotContainsString('#7B68EE', $html);
        $this->assertStringNotContainsString('Sent by SimpleAd Manager', $html);
    }

    #[DataProvider('mailables')]
    public function test_it_has_a_subject(string $factory): void
    {
        $subject = $this->{$factory}()->envelope()->subject;

        $this->assertIsString($subject);
        $this->assertNotSame('', trim((string) $subject));
    }

    public function test_the_client_report_stays_romanian_even_when_the_app_runs_in_english(): void
    {
        // 18 live schedules send this to Romanian-speaking clients. It was
        // hardcoded Romanian; translating it must not have flipped them to the
        // app locale, which is `en` in production.
        $this->app->setLocale('en');

        $html = $this->reportGenerated()->render();

        $this->assertStringContainsString('Raport de mentenanță', $html);
        $this->assertStringNotContainsString('Maintenance report', $html);
    }

    public function test_subjects_follow_the_recipient_locale(): void
    {
        $this->app->setLocale('ro');

        $this->assertStringContainsString('Backup eșuat', (string) $this->backupFailed()->envelope()->subject);
        $this->assertStringContainsString('Limită de stocare', (string) $this->storageQuota()->envelope()->subject);
    }

    public function test_every_string_in_an_email_has_a_romanian_translation(): void
    {
        $sources = array_merge(
            glob(resource_path('views/mail/*.blade.php')) ?: [],
            glob(resource_path('views/mail/backup/v2/*.blade.php')) ?: [],
            glob(resource_path('views/components/mail/*.blade.php')) ?: [],
            glob(app_path('Mail/*.php')) ?: [],
            glob(app_path('Mail/Backup/V2/*.php')) ?: [],
            [app_path('Services/Uptime/FailureReasonPresenter.php')],
        );

        $romanian = json_decode((string) file_get_contents(lang_path('ro.json')), true);
        $untranslated = [];

        foreach ($sources as $file) {
            preg_match_all(
                "/(?:__|trans_choice)\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
                (string) file_get_contents($file),
                $matches
            );

            foreach ($matches[1] as $key) {
                $key = str_replace("\\'", "'", $key);
                if (! array_key_exists($key, $romanian)) {
                    $untranslated[] = basename($file).': '.$key;
                }
            }
        }

        $this->assertSame([], $untranslated, "These email strings have no lang/ro.json entry:\n".implode("\n", $untranslated));
    }

    private function site(): Site
    {
        $site = new Site;
        $site->forceFill(['id' => 1, 'name' => 'Example Shop', 'url' => 'https://www.example-shop.de/']);

        return $site;
    }

    private function performanceMonitor(): PerformanceMonitor
    {
        $monitor = new PerformanceMonitor;
        $monitor->forceFill(['id' => 1, 'site_id' => 1]);
        $monitor->setRelation('site', $this->site());

        return $monitor;
    }

    private function backupFailed(): Mailable
    {
        $backup = new Backup;
        $backup->forceFill(['id' => 1, 'type' => 'full', 'trigger' => 'scheduled', 'started_at' => now()]);

        return new BackupAlertMail($this->site(), $backup, 'Connection closed while transferring wp-content.');
    }

    private function backupCompleted(): Mailable
    {
        $session = new BackupSession;
        $session->forceFill([
            'id' => 4821,
            'type' => 'full',
            'resource_profile' => 'shared_hosting',
            'completed_at' => now(),
            'verified_at' => now(),
        ]);

        return new BackupV2SuccessMail($this->site(), $session);
    }

    private function storageQuota(): Mailable
    {
        $destination = new StorageDestination;
        $destination->forceFill([
            'id' => 1,
            'name' => 'Hetzner Storage Box',
            'type' => 's3',
            'used_bytes' => 963_000_000_000,
            'quota_bytes' => 1_000_000_000_000,
        ]);

        return new StorageQuotaReachedMail($destination);
    }

    private function performanceDrop(): Mailable
    {
        return new PerformanceAlertMail($this->performanceMonitor(), 'mobile', 84, 51);
    }

    private function budgetViolation(): Mailable
    {
        $test = new PerformanceTest;
        $test->forceFill(['id' => 1, 'strategy' => 'mobile']);

        return new BudgetViolationMail(
            $this->performanceMonitor(),
            [['key' => 'Largest Contentful Paint', 'actual' => '4.8 s', 'budget' => '2.5 s']],
            $test,
        );
    }

    private function dailyDigest(): Mailable
    {
        return new DailyDigestMail([
            'date' => 'Friday, 7 August 2026',
            'total_sites' => 29,
            'sites_up' => 28,
            'sites_down' => 1,
            'incidents_24h' => 2,
            'resolved_24h' => 1,
            'backups_24h' => 27,
            'backups_failed_24h' => 2,
            'updates_available' => 6,
        ]);
    }

    private function reportGenerated(): Mailable
    {
        $report = new Report;
        $report->forceFill([
            'id' => 1,
            'period_start' => now()->startOfMonth()->subMonth(),
            'period_end' => now()->startOfMonth()->subDay(),
            'file_size' => 1_842_000,
            'file_name' => 'raport.pdf',
        ]);

        return new ReportGeneratedMail($report, $this->site());
    }
}
