<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Report;
use Illuminate\Support\Facades\Storage;

class ClientPortalController extends Controller
{
    public function show(string $token)
    {
        $client = Client::where('portal_token', $token)
            ->where('portal_enabled', true)
            ->firstOrFail();

        $sites = $client->sites()
            ->with(['uptimeMonitor', 'latestCompletedBackup', 'performanceMonitor'])
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

    public function downloadReport(string $token, Report $report)
    {
        $client = Client::where('portal_token', $token)
            ->where('portal_enabled', true)
            ->firstOrFail();

        // Verify report belongs to client's sites
        $siteIds = $client->sites()->pluck('id');
        if (! $siteIds->contains($report->site_id)) {
            abort(403);
        }

        if (! $report->file_path) {
            abort(404);
        }

        $filePath = Storage::disk('local')->path($report->file_path);
        if (! file_exists($filePath)) {
            abort(404);
        }

        return response()->download($filePath, $report->file_name ?? 'report.pdf');
    }
}
