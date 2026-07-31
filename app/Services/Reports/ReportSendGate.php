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
 * Barrier 3 ("a section has no data because an integration failed") is now live:
 * every integration-backed gatherer stamps a uniform marker
 * ({@see BaseReportSectionGatherer::INTEGRATION_KEY}) saying whether its source is
 * configured and whether it answered, which is what separates a broken integration
 * from one that was never set up.
 *
 * Barrier 2 is now complete: alongside the uptime floor it holds a report whose
 * traffic moved by a multiple rather than a percentage — the snapshot carries the
 * previous period's figures, which is what the deferral was waiting on.
 */
class ReportSendGate
{
    /** Spec headline threshold — uptime under this holds the report. */
    public const MIN_UPTIME_PCT = 99.0;

    /**
     * "Câteva ori" read as three. A 3x move month over month is not growth, it is
     * a measurement that changed.
     */
    public const TRAFFIC_SPIKE_FACTOR = 3.0;

    /**
     * Below this, ratios are noise: 4 users becoming 20 is a five-fold rise and
     * means nothing. Without a floor a quiet site would hold its report forever.
     */
    public const TRAFFIC_FLOOR = 50;

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

            // Barrier 2b — traffic moved by a multiple, not a percentage. A tripling
            // is almost always a measurement problem (a tag fired twice, a bot run,
            // a property change), and a report that presents it as growth is worse
            // than a late one. A collapse to a third is the same signal inverted.
            foreach ($this->trafficAnomalies($data) as $anomaly) {
                $reasons[] = $anomaly;
            }

            // Barrier 3 — a section is empty because its integration failed.
            // "mai bine omisă decât afișată cu zero": a configured integration that
            // returned nothing is a hole in the report, while an integration that
            // was never set up is simply a section the client does not get.
            foreach ($this->failedIntegrations($data) as $failure) {
                $reasons[] = sprintf(
                    'the %s section has no data (%s)',
                    $failure['section'],
                    $failure['reason'],
                );
            }

            $reasons = array_values(array_unique($reasons));

            return ['send' => $reasons === [], 'reasons' => $reasons];
        } catch (\Throwable $e) {
            // Fail-safe: an evaluation we cannot finish must never green-light a send.
            return ['send' => false, 'reasons' => ['safety-barrier evaluation failed: '.$e->getMessage()]];
        }
    }

    /**
     * SPEC §12.3: "o valoare iese dintr-un interval rezonabil — … salt de trafic de
     * câteva ori".
     *
     * Both directions are held: a tripling and a collapse to a third are equally
     * likely to be a broken measurement, and equally embarrassing to send.
     *
     * Small numbers are ignored. Going from 4 users to 20 is a five-fold rise and
     * means nothing; the floor is what stops a quiet site from holding its report
     * every month.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function trafficAnomalies(array $data): array
    {
        $analytics = $data['analytics'] ?? [];
        $out = [];

        foreach (['users', 'pageviews'] as $metric) {
            $current = $analytics[$metric.'_current'] ?? null;
            $previous = $analytics[$metric.'_previous'] ?? null;

            if (! is_numeric($current) || ! is_numeric($previous)) {
                continue;
            }

            $current = (float) $current;
            $previous = (float) $previous;

            if ($previous < self::TRAFFIC_FLOOR || $current < self::TRAFFIC_FLOOR) {
                continue;
            }

            $ratio = $current / $previous;

            if ($ratio >= self::TRAFFIC_SPIKE_FACTOR || $ratio <= 1 / self::TRAFFIC_SPIKE_FACTOR) {
                $out[] = sprintf(
                    '%s moved %.1fx against the previous period (%d → %d)',
                    $metric,
                    $ratio,
                    (int) $previous,
                    (int) $current,
                );
            }
        }

        return $out;
    }

    /**
     * Sections whose integration is configured but produced nothing.
     *
     * Each integration-backed gatherer stamps {@see BaseReportSectionGatherer::INTEGRATION_KEY}
     * into its slice of the snapshot; anything without the marker (a section that
     * reads only local data) cannot fail this way and is ignored.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{section: string, reason: string}>
     */
    private function failedIntegrations(array $data): array
    {
        $failures = [];

        foreach ($data as $sectionKey => $section) {
            if (! is_array($section)) {
                continue;
            }

            $marker = $section[BaseReportSectionGatherer::INTEGRATION_KEY] ?? null;

            if (! is_array($marker) || ($marker['ok'] ?? true) !== false) {
                continue;
            }

            $failures[] = [
                'section' => (string) ($marker['section'] ?? $sectionKey),
                'reason' => (string) ($marker['reason'] ?? 'integration returned no data'),
            ];
        }

        return $failures;
    }
}
