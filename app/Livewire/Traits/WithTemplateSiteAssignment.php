<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\Site;
use App\Services\ReportManagementService;
use Illuminate\Support\Facades\DB;

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
        $this->resetValidation();
        $this->assignTemplateId = $templateId;
        $this->assignedSiteIds = Site::where('report_template_id', $templateId)->pluck('id')->toArray();
        $this->siteSearch = '';
        $this->showAssignSitesModal = true;
        $this->dispatch('open-modal-assign-sites');
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
        // Both the template id and every site id come straight from the browser
        // and drive a mass update of sites.report_template_id, so they are
        // checked against the tables before anything is written.
        $this->validate([
            'assignTemplateId' => ['required', 'integer', 'exists:report_templates,id'],
            'assignedSiteIds' => ['array'],
            'assignedSiteIds.*' => ['integer', 'distinct', 'exists:sites,id'],
        ], [
            'assignTemplateId.required' => __('Pick a template before assigning sites.'),
            'assignTemplateId.exists' => __('That template no longer exists.'),
            'assignedSiteIds.*.exists' => __('One of the selected sites no longer exists.'),
            'assignedSiteIds.*.integer' => __('One of the selected sites is invalid.'),
            'assignedSiteIds.*.distinct' => __('One of the selected sites is listed twice.'),
        ]);

        DB::transaction(function (): void {
            // Unassign sites that were removed
            Site::where('report_template_id', $this->assignTemplateId)
                ->whereNotIn('id', $this->assignedSiteIds)
                ->update(['report_template_id' => null]);

            // Assign selected sites
            if (! empty($this->assignedSiteIds)) {
                Site::whereIn('id', $this->assignedSiteIds)
                    ->update(['report_template_id' => $this->assignTemplateId]);
            }
        });

        $this->showAssignSitesModal = false;
        $this->dispatch('close-modal-assign-sites');
        $this->dispatch('notify', type: 'success', message: __(':count site(s) assigned.', ['count' => count($this->assignedSiteIds)]));
    }

    public function openBulkScheduleModal(int $templateId): void
    {
        $this->resetValidation();
        $this->bulkScheduleTemplateId = $templateId;
        $this->bulkScheduleTime = '08:00';
        $this->bulkSchedulePeriod = 'last_month';
        $this->bulkScheduleRecipientEmails = '';
        $this->bulkScheduleSendCopyToAdmin = true;
        $this->showBulkScheduleModal = true;
        $this->dispatch('open-modal-bulk-schedule');
    }

    public function saveBulkSchedule(): void
    {
        $this->validate([
            'bulkScheduleTemplateId' => ['required', 'integer', 'exists:report_templates,id'],
            'bulkScheduleTime' => ['required', 'date_format:H:i'],
            'bulkSchedulePeriod' => ['required', 'in:last_7_days,last_30_days,last_month'],
        ], [
            'bulkScheduleTemplateId.required' => __('Pick a template before creating schedules.'),
            'bulkScheduleTemplateId.exists' => __('That template no longer exists.'),
            'bulkScheduleTime.required' => __('Choose a send time.'),
            'bulkScheduleTime.date_format' => __('Enter the send time as HH:MM.'),
            'bulkSchedulePeriod.required' => __('Choose a report period.'),
            'bulkSchedulePeriod.in' => __('Choose a report period.'),
        ]);

        // Get all sites assigned to this template that don't already have a schedule
        $siteIds = Site::where('report_template_id', $this->bulkScheduleTemplateId)
            ->whereDoesntHave('reportSchedules')
            ->pluck('id');

        if ($siteIds->isEmpty()) {
            $this->showBulkScheduleModal = false;
            $this->dispatch('close-modal-bulk-schedule');
            $this->dispatch('notify', type: 'success', message: __('All assigned sites already have schedules.'));

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
        $this->dispatch('close-modal-bulk-schedule');
        $this->dispatch('notify', type: 'success', message: __(':count schedule(s) created for assigned sites.', ['count' => $created]));
    }
}
