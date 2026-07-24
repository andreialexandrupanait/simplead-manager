<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Faza D6: serves a published audit report's stored HTML snapshot by slug.
 * Public (no auth). When the report is token-gated, a matching `?t=` token is
 * required (constant-time compare). Intended to sit behind rapoarte.simplead.ro.
 */
class PublicAuditReportController extends Controller
{
    public function show(string $slug, Request $request): Response
    {
        $report = AuditReport::query()->where('slug', $slug)->firstOrFail();

        if ($report->token_required) {
            $provided = (string) $request->query('t', '');
            if ($provided === '' || ! hash_equals((string) $report->access_token, $provided)) {
                abort(403);
            }
        }

        return response((string) $report->html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
