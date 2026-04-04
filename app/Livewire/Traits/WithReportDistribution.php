<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Services\ReportManagementService;

trait WithReportDistribution
{
    public bool $showSendModal = false;

    public ?int $sendReportId = null;

    public string $sendToEmail = '';

    public string $bulkSendEmail = '';

    public function openSendModal(int $reportId): void
    {
        $this->sendReportId = $reportId;
        $this->sendToEmail = '';
        $this->showSendModal = true;
    }

    public function sendReport(): void
    {
        $this->validate([
            'sendToEmail' => 'required|email',
        ]);

        /** @var \App\Models\Report $report */
        $report = $this->site->reports()->findOrFail($this->sendReportId);

        app(ReportManagementService::class)->sendReport($report, [$this->sendToEmail]);

        $this->showSendModal = false;
        $this->sendReportId = null;
        session()->flash('report-success', 'Report sent to '.$this->sendToEmail);
    }

    public function bulkSend(array $ids, string $email): void
    {
        $this->bulkSendEmail = $email;

        $this->validate([
            'bulkSendEmail' => 'required|email',
        ]);

        $count = app(ReportManagementService::class)->bulkSend($ids, $email, $this->site);

        $this->bulkSendEmail = '';
        session()->flash('report-success', $count.' report(s) sent to '.$email);
    }

    public function bulkDelete(array $ids): void
    {
        $count = app(ReportManagementService::class)->deleteReports($ids, $this->site);
        session()->flash('report-success', $count.' report(s) deleted.');
        $this->resetPage();
    }
}
