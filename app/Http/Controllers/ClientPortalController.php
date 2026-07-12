<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Models\Client;
use App\Models\Report;
use Illuminate\Support\Facades\Storage;

class ClientPortalController extends Controller
{
    /**
     * Resolve the client behind a public portal token, or 404. The portal is
     * only live when the feature is enabled AND the client is active — an
     * archived/inactive client's portal is not publicly accessible (P2-06).
     */
    private function resolvePortalClient(string $token): Client
    {
        return Client::where('portal_token', $token)
            ->portalAccessible()
            ->firstOrFail();
    }

    /**
     * A report may only surface in the portal once it is fully generated:
     * status COMPLETED and its data snapshot populated. Anything pending,
     * generating or failed would render an empty/broken view (P2-07).
     */
    private function reportIsRenderable(Report $report): bool
    {
        return $report->status === ReportStatus::Completed
            && ! empty($report->data_snapshot);
    }

    public function show(string $token)
    {
        $client = $this->resolvePortalClient($token);

        $sites = $client->sites()
            ->with(['uptimeMonitor', 'uptimeMonitor.ongoingIncident', 'latestCompletedBackup', 'performanceMonitor', 'securityMonitor', 'backupConfig'])
            ->get();

        $siteIds = $sites->pluck('id');

        $reports = Report::whereIn('site_id', $siteIds)
            ->where('status', 'completed')
            ->whereNotNull('file_path')
            ->with('site:id,name')
            ->orderByDesc('generated_at')
            ->limit(20)
            ->get();

        return view('client-portal.show', [
            'client' => $client,
            'sites' => $sites,
            'reports' => $reports,
        ]);
    }

    public function viewReport(string $token, Report $report)
    {
        $client = $this->resolvePortalClient($token);

        $siteIds = $client->sites()->pluck('id');
        if (! $siteIds->contains($report->site_id)) {
            abort(403);
        }

        // Never render an incomplete/failed/generating report — it would show a
        // broken/empty page. Only completed reports with a data snapshot pass.
        if (! $this->reportIsRenderable($report)) {
            abort(404);
        }

        return view('client-portal.report', [
            'client' => $client,
            'report' => $report,
        ]);
    }

    public function downloadReport(string $token, Report $report)
    {
        $client = $this->resolvePortalClient($token);

        // Verify report belongs to client's sites
        $siteIds = $client->sites()->pluck('id');
        if (! $siteIds->contains($report->site_id)) {
            abort(403);
        }

        if ($report->status !== ReportStatus::Completed || ! $report->file_path) {
            abort(404);
        }

        $filePath = Storage::disk('local')->path($report->file_path);
        if (! file_exists($filePath)) {
            abort(404);
        }

        return response()->download($filePath, $report->file_name ?? 'report.pdf');
    }
}
