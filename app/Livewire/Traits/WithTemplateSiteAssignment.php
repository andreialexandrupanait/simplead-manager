<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\Site;
use App\Services\ReportManagementService;

trait WithTemplateSiteAssignment
{
    // Assign sites modal
    public bool $showAssignSitesModal = false;

    public ?int $assignTemplateId = null;

    public array $assignedSiteIds = [];

    public string $siteSearch = '';

    // Bulk schedule modal
    public bool $showBulkScheduleModal = false;

    public ?int $bulkScheduleTemplateId = null;

    public string $bulkScheduleTime = '08:00';

    public string $bulkSchedulePeriod = 'last_month';

    public string $bulkScheduleRecipientEmails = '';

    public bool $bulkScheduleSendCopyToAdmin = true;

    public function openAssignSites(int $templateId): void
    {
        $this->assignTemplateId = $templateId;
        $this->assignedSiteIds = Site::where('report_template_id', $templateId)->pluck('id')->toArray();
        $this->siteSearch = '';
        $this->showAssignSitesModal = true;
    }

    public function toggleSiteAssignment(int $siteId): void
    {
        if (in_array($siteId, $this->assignedSiteIds)) {
            $this->assignedSiteIds = array_values(array_diff($this->assignedSiteIds, [$siteId]));
        } else {
            $this->assignedSiteIds[] = $siteId;
        }
    }

    public function saveAssignedSites(): void
    {
        if (! $this->assignTemplateId) {
            return;
        }

        // Unassign sites that were removed
        Site::where('report_template_id', $this->assignTemplateId)
            ->whereNotIn('id', $this->assignedSiteIds)
            ->update(['report_template_id' => null]);

        // Assign selected sites
        if (! empty($this->assignedSiteIds)) {
            Site::whereIn('id', $this->assignedSiteIds)
                ->update(['report_template_id' => $this->assignTemplateId]);
        }

        $this->showAssignSitesModal = false;
        session()->flash('template-success', count($this->assignedSiteIds).' site(s) assigned.');
    }

    public function openBulkScheduleModal(int $templateId): void
    {
        $this->bulkScheduleTemplateId = $templateId;
        $this->bulkScheduleTime = '08:00';
        $this->bulkSchedulePeriod = 'last_month';
        $this->bulkScheduleRecipientEmails = '';
        $this->bulkScheduleSendCopyToAdmin = true;
        $this->showBulkScheduleModal = true;
    }

    public function saveBulkSchedule(): void
    {
        if (! $this->bulkScheduleTemplateId) {
            return;
        }

        $this->validate([
            'bulkScheduleTime' => 'required|date_format:H:i',
            'bulkSchedulePeriod' => 'required|in:last_7_days,last_30_days,last_month',
        ]);

        // Get all sites assigned to this template that don't already have a schedule
        $siteIds = Site::where('report_template_id', $this->bulkScheduleTemplateId)
            ->whereDoesntHave('reportSchedules')
            ->pluck('id');

        if ($siteIds->isEmpty()) {
            $this->showBulkScheduleModal = false;
            session()->flash('template-success', 'All assigned sites already have schedules.');

            return;
        }

        $service = app(ReportManagementService::class);
        $created = 0;

        foreach ($siteIds as $siteId) {
            $site = Site::find($siteId);
            if (! $site) {
                continue;
            }

            $service->saveSchedule($site, [
                'template_id' => $this->bulkScheduleTemplateId,
                'is_active' => true,
                'frequency' => 'monthly',
                'day_of_month' => 1,
                'time' => $this->bulkScheduleTime,
                'timezone' => 'Europe/Bucharest',
                'period' => $this->bulkSchedulePeriod,
                'recipient_emails_raw' => $this->bulkScheduleRecipientEmails,
                'send_copy_to_admin' => $this->bulkScheduleSendCopyToAdmin,
                'email_subject' => '',
                'email_body' => '',
            ]);

            $created++;
        }

        $this->showBulkScheduleModal = false;
        session()->flash('template-success', $created.' schedule(s) created for assigned sites.');
    }
}
