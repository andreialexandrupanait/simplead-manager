<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Report;
use App\Models\UptimeIncident;

/**
 * SPEC §12.3 — mandatory report safety barriers. Decides whether a freshly
 * generated report is safe to auto-send. **Fail-safe by design:** if a barrier
 * cannot be evaluated, the report is HELD, not sent ("Primul raport care pleacă
 * singur cu date greșite face mai mult rău decât zece rapoarte trimise târziu").
 * A held report never leaves silently — the caller records the reason and
 * notifies the operator so it can be reviewed and sent (or not) by hand.
 *
 * v1 implements the two unambiguous, worst-case barriers the spec names outright:
 *   1. an unresolved critical incident on the site (an open uptime incident, or
 *      the site being down right now);
 *   2. an out-of-range headline value — uptime below {@see self::MIN_UPTIME_PCT}.
 *
 * Deferred (documented in E2E-VERIFICAT.md — need design/data not yet available):
 *   - "a section has no data because an integration failed": report sections carry
 *     no uniform failure marker today (uptime has `available`, analytics/GSC do
 *     not), so a failed integration cannot be told apart from a not-configured one
 *     without per-gatherer error surfacing.
 *   - "traffic jumped several times": needs prior-period comparison data.
 */
class ReportSendGate
{
    /** Spec headline threshold — uptime under this holds the report. */
    public const MIN_UPTIME_PCT = 99.0;

    /**
     * @return array{send: bool, reasons: list<string>}
     */
    public function evaluate(Report $report): array
    {
        try {
            $reasons = [];
            $data = $report->data_snapshot ?? [];
            $site = $report->site;

            // Barrier 1 — an unresolved critical incident on the site. Checked live
            // (at send time), not just within the reporting period: a report must
            // not go out while the site is actively in trouble.
            if ($site !== null) {
                // Incidents hang off the uptime monitor (uptime_incidents.monitor_id
                // → uptime_monitors.site_id), not off the site directly.
                $openIncidents = UptimeIncident::query()
                    ->whereNull('resolved_at')
                    ->whereHas('monitor', fn ($q) => $q->where('site_id', $site->id))
                    ->count();
                if ($openIncidents > 0) {
                    $reasons[] = $openIncidents.' unresolved uptime incident(s) open on the site';
                }

                if ($site->is_up === false) {
                    $reasons[] = 'the site is currently down';
                }
            }

            // Barrier 2 — out-of-range headline availability (below the SLA floor).
            $uptimePct = $data['uptime']['uptime_percentage'] ?? null;
            if (is_numeric($uptimePct) && (float) $uptimePct < self::MIN_UPTIME_PCT) {
                $reasons[] = sprintf(
                    'uptime %.2f%% is below the %.0f%% floor',
                    (float) $uptimePct,
                    self::MIN_UPTIME_PCT,
                );
            }

            $reasons = array_values(array_unique($reasons));

            return ['send' => $reasons === [], 'reasons' => $reasons];
        } catch (\Throwable $e) {
            // Fail-safe: an evaluation we cannot finish must never green-light a send.
            return ['send' => false, 'reasons' => ['safety-barrier evaluation failed: '.$e->getMessage()]];
        }
    }
}
