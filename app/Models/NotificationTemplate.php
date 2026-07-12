<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $event
 * @property string $title_template
 * @property string $message_template
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class NotificationTemplate extends Model
{
    protected $fillable = [
        'event',
        'title_template',
        'message_template',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Available placeholder variables for templates.
     */
    public const PLACEHOLDERS = [
        '{site_name}' => 'Site name',
        '{site_url}' => 'Site URL',
        '{event}' => 'Event type',
        '{severity}' => 'Severity level',
        '{details}' => 'Event details/message',
    ];

    /**
     * Known events with default labels.
     */
    public const EVENTS = [
        'site_down' => 'Site Down',
        'site_recovered' => 'Site Recovered',
        'site_degraded' => 'Site Degraded',
        'site_disconnected' => 'Site Disconnected',
        'site_reconnected' => 'Site Reconnected',
        'backup_failed' => 'Backup Failed',
        'restore_failed' => 'Restore Failed',
        'backup_verify_failures' => 'Backup Verification Failed',
        'performance_drop' => 'Performance Drop',
        'budget_violation' => 'Budget Violation',
        'security_score_critical' => 'Security Score Critical',
        'vulnerability_detected' => 'Vulnerability Detected',
        'core_files_modified' => 'Core Files Modified',
        'plugin_conflict_detected' => 'Plugin Conflict',
        'abandoned_plugins_found' => 'Abandoned Plugins',
        'email_blacklisted' => 'Email Blacklisted',
        'safe_update_failed' => 'Safe Update Failed',
        'safe_update_rolled_back' => 'Safe Update Rolled Back',
        'report_reminder' => 'Report Reminder',
        'connection_validation_failed' => 'Connection Failed',
        'dns_changed' => 'DNS Records Changed',
        'php_fatal_error' => 'PHP Fatal Error Detected',
        'content_stale' => 'Stale Content Detected',
        'connector_update_failed' => 'Connector Update Failed',
        'theme_files_modified' => 'Theme Files Modified',
        'wordpress_version_eol' => 'WordPress Version EOL',
        'app_backup_completed' => 'App Backup Completed',
        'app_backup_failed' => 'App Backup Failed',
        'app_backup_degraded' => 'App Backup Degraded (Local-Only)',
        'db_dump_offsite_failed' => 'Database Dump Off-site Push Failed',
        'domain_expiring' => 'Domain Expiring',
        'horizon_stopped' => 'Horizon Stopped',
        'horizon_long_wait' => 'Horizon Long Wait',
        'job_failures' => 'Repeated Job Failures',
        'scheduled_task_failing' => 'Scheduled Task Failing',
        'test' => 'Test Notification',
    ];

    /**
     * Render the title with placeholder replacement.
     */
    public function renderTitle(?Site $site, string $fallback, array $extra = []): string
    {
        if (! $this->is_active) {
            return $fallback;
        }

        return $this->replacePlaceholders($this->title_template, $site, $extra);
    }

    /**
     * Render the message with placeholder replacement.
     */
    public function renderMessage(?Site $site, string $fallback, array $extra = []): string
    {
        if (! $this->is_active) {
            return $fallback;
        }

        return $this->replacePlaceholders($this->message_template, $site, $extra);
    }

    protected function replacePlaceholders(string $template, ?Site $site, array $extra = []): string
    {
        $replacements = [
            '{site_name}' => $site->name ?? 'N/A',
            '{site_url}' => $site->url ?? 'N/A',
            '{event}' => $this->event,
            '{severity}' => $extra['severity'] ?? 'warning',
            '{details}' => $extra['details'] ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
