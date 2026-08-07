<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Renders any of the transactional emails with sample data, so a template can be
 * reviewed without waiting for the event that sends it.
 *
 * Nothing is written to the database — every model is built in memory — which
 * makes this safe to run against production, where the real branding and the
 * real SMTP settings live.
 *
 * The uptime alert has its own command (mail:preview-uptime) because it needs an
 * incident and a monitor to build a realistic failure.
 */
class PreviewMailCommand extends Command
{
    protected $signature = 'mail:preview
        {template : backup-failed, backup-completed, storage-quota, performance-drop, budget, digest, report}
        {--send= : Send the rendered email to this address instead of printing it}';

    protected $description = 'Render or send a transactional email with sample data';

    private const TEMPLATES = [
        'backup-failed',
        'backup-completed',
        'storage-quota',
        'performance-drop',
        'budget',
        'digest',
        'report',
    ];

    public function handle(): int
    {
        $template = (string) $this->argument('template');

        if (! in_array($template, self::TEMPLATES, true)) {
            $this->error('Unknown template. Pick one of: '.implode(', ', self::TEMPLATES).'.');

            return self::FAILURE;
        }

        $mailable = $this->build($template);

        $address = $this->option('send');
        if ($address === null) {
            $this->line($mailable->render());

            return self::SUCCESS;
        }

        Mail::to((string) $address)->send($mailable);
        $this->info("Sent the {$template} email to {$address}.");

        return self::SUCCESS;
    }

    private function build(string $template): Mailable
    {
        return match ($template) {
            'backup-failed' => new BackupAlertMail(
                $this->site(),
                $this->backup(),
                'The connection to the remote host was closed while transferring wp-content.',
            ),
            'backup-completed' => new BackupV2SuccessMail($this->site(), $this->session()),
            'storage-quota' => new StorageQuotaReachedMail($this->destination()),
            'performance-drop' => new PerformanceAlertMail($this->performanceMonitor(), 'mobile', 84, 51),
            'budget' => new BudgetViolationMail(
                $this->performanceMonitor(),
                [
                    ['key' => 'Largest Contentful Paint', 'actual' => '4.8 s', 'budget' => '2.5 s'],
                    ['key' => 'Total Blocking Time', 'actual' => '710 ms', 'budget' => '200 ms'],
                    ['key' => 'Page weight', 'actual' => '5.1 MB', 'budget' => '2.0 MB'],
                ],
                $this->performanceTest(),
            ),
            'digest' => new DailyDigestMail([
                'date' => now()->format('l, j F Y'),
                'total_sites' => 29,
                'sites_up' => 28,
                'sites_down' => 1,
                'incidents_24h' => 2,
                'resolved_24h' => 1,
                'backups_24h' => 27,
                'backups_failed_24h' => 2,
                'updates_available' => 6,
            ]),
            'report' => new ReportGeneratedMail($this->report(), $this->site()),
            default => throw new \LogicException("Unhandled template {$template}."),
        };
    }

    private function report(): Report
    {
        $report = new Report;
        $report->forceFill([
            'id' => 1,
            'period_start' => now()->startOfMonth()->subMonth(),
            'period_end' => now()->startOfMonth()->subDay(),
            'file_size' => 1_842_000,
            'file_name' => 'raport.pdf',
        ]);

        return $report;
    }

    private function site(): Site
    {
        $site = new Site;
        $site->forceFill(['id' => 1, 'name' => 'Example Shop', 'url' => 'https://www.example-shop.de/']);

        return $site;
    }

    private function backup(): Backup
    {
        $backup = new Backup;
        $backup->forceFill([
            'id' => 1,
            'type' => 'full',
            'trigger' => 'scheduled',
            'started_at' => now()->subMinutes(22),
        ]);

        return $backup;
    }

    private function session(): BackupSession
    {
        $session = new BackupSession;
        $session->forceFill([
            'id' => 4821,
            'type' => 'full',
            'resource_profile' => 'shared_hosting',
            'completed_at' => now()->subMinutes(8),
            'verified_at' => now()->subMinutes(6),
        ]);

        return $session;
    }

    private function destination(): StorageDestination
    {
        $destination = new StorageDestination;
        $destination->forceFill([
            'id' => 1,
            'name' => 'Hetzner Storage Box',
            'type' => 's3',
            'used_bytes' => 963_000_000_000,
            'quota_bytes' => 1_000_000_000_000,
        ]);

        return $destination;
    }

    private function performanceMonitor(): PerformanceMonitor
    {
        $monitor = new PerformanceMonitor;
        $monitor->forceFill(['id' => 1, 'site_id' => 1]);
        $monitor->setRelation('site', $this->site());

        return $monitor;
    }

    private function performanceTest(): PerformanceTest
    {
        $test = new PerformanceTest;
        $test->forceFill(['id' => 1, 'strategy' => 'mobile']);

        return $test;
    }
}
