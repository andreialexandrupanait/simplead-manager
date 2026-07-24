<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditStatus;
use App\Models\Audit;
use App\Models\AuditReport;

/**
 * Faza D6: publishes (or re-publishes) an audit's public report. Republish keeps
 * the slug, access token and client implementation toggles stable (links stay
 * live) and bumps the version. The audit moves to PUBLICAT.
 */
final class PublishAuditReport
{
    public function __construct(
        private readonly AuditReportBuilder $builder = new AuditReportBuilder,
    ) {}

    public function publish(Audit $audit): AuditReport
    {
        $existing = AuditReport::query()->where('audit_id', $audit->id)->first();

        $toggles = null;
        $slug = AuditReportSlug::resolve(
            AuditReportSlug::baseFromDomain($audit->url),
            static fn (string $candidate): bool => AuditReport::query()->where('slug', $candidate)->exists(),
        );
        $token = bin2hex(random_bytes(16));
        $tokenRequired = false;
        $version = 1;

        if ($existing instanceof AuditReport) {
            $toggles = is_array($existing->implemented_state) ? $existing->implemented_state : null;
            $slug = $existing->slug;
            $token = $existing->access_token;
            $tokenRequired = (bool) $existing->token_required;
            $version = (int) $existing->version + 1;
        }

        $data = $this->builder->build($audit, $toggles);
        $html = view('reports.audit', ['report' => $data, 'toggles' => $toggles ?? []])->render();

        $report = AuditReport::query()->updateOrCreate(
            ['audit_id' => $audit->id],
            [
                'slug' => $slug,
                'access_token' => $token,
                'token_required' => $tokenRequired,
                'version' => $version,
                'html' => $html,
                'published_at' => now(),
            ],
        );

        $audit->update(['status' => AuditStatus::Publicat]);

        return $report->refresh();
    }
}
