<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Faza 5.2 — WooCommerce store-health signal mapping.
 *
 * Pure translation from the connector's /woo-health payload to the normalized
 * signals stored on woo_checks plus a traffic-light severity. Kept side-effect free
 * (no DB, no HTTP) so the mapping is unit-testable on its own; the CheckWooHealth
 * job owns the connector call, persistence and alerting.
 *
 * Severity mapping (matches WooCheck::getStatusColorAttribute):
 *   red    → a payment gateway is down (enabled-but-unavailable, or none enabled),
 *            or a wired checkout probe returned a non-200 status
 *   yellow → stuck pending orders over threshold, or the scheduled-sales cron is gone
 *   green  → everything nominal
 * A non-applicable site (no WooCommerce) maps to applicable=false and never alerts.
 */
class WooHealthService
{
    /**
     * @param  array<string, mixed>  $response  Decoded connector /woo-health body.
     * @param  int|null  $checkoutStatus  Optional Manager-side checkout probe HTTP status.
     * @return array{
     *     applicable: bool,
     *     checkout_status: int|null,
     *     gateways_ok: bool,
     *     pending_orders_count: int,
     *     pending_over_threshold: bool,
     *     discount_cron_scheduled: bool,
     *     gateways: array<int, array<string, mixed>>,
     *     color: string,
     *     severity: string|null,
     *     reasons: array<int, string>
     * }
     */
    public static function evaluate(array $response, ?int $checkoutStatus = null): array
    {
        $applicable = (bool) ($response['applicable'] ?? false);

        if (! $applicable) {
            return [
                'applicable' => false,
                'checkout_status' => null,
                'gateways_ok' => true,
                'pending_orders_count' => 0,
                'pending_over_threshold' => false,
                'discount_cron_scheduled' => true,
                'gateways' => [],
                'color' => 'gray',
                'severity' => null,
                'reasons' => [],
            ];
        }

        $gateways = is_array($response['gateways'] ?? null) ? $response['gateways'] : [];
        $gatewaysOk = self::gatewaysHealthy($gateways);

        $pendingCount = (int) ($response['pending_orders_count'] ?? 0);
        $pendingOver = (bool) ($response['pending_over_threshold'] ?? ($pendingCount > 0));
        $cronScheduled = (bool) ($response['scheduled_sales_cron_present'] ?? true);

        $checkoutBad = $checkoutStatus !== null && $checkoutStatus !== 200;

        $reasons = [];
        $severity = null;
        $color = 'green';

        // Red conditions (checkout is broken) take precedence over yellow.
        if (! $gatewaysOk) {
            $reasons[] = 'gateway indisponibil';
        }
        if ($checkoutBad) {
            $reasons[] = "checkout a răspuns {$checkoutStatus}";
        }

        if (! $gatewaysOk || $checkoutBad) {
            $severity = 'critical';
            $color = 'red';
        } else {
            if ($pendingOver) {
                $reasons[] = "{$pendingCount} comenzi pending blocate";
            }
            if (! $cronScheduled) {
                $reasons[] = 'cron reduceri programate lipsă';
            }

            if ($pendingOver || ! $cronScheduled) {
                $severity = 'warning';
                $color = 'yellow';
            }
        }

        return [
            'applicable' => true,
            'checkout_status' => $checkoutStatus,
            'gateways_ok' => $gatewaysOk,
            'pending_orders_count' => $pendingCount,
            'pending_over_threshold' => $pendingOver,
            'discount_cron_scheduled' => $cronScheduled,
            'gateways' => $gateways,
            'color' => $color,
            'severity' => $severity,
            'reasons' => $reasons,
        ];
    }

    /**
     * Gateways are healthy when at least one gateway is enabled AND every enabled
     * gateway is actually available. Zero enabled gateways means the store cannot
     * take a payment → treated as down.
     *
     * @param  array<int, array<string, mixed>>  $gateways
     */
    private static function gatewaysHealthy(array $gateways): bool
    {
        $enabled = array_filter($gateways, static fn ($g): bool => (bool) ($g['enabled'] ?? false));

        if ($enabled === []) {
            return false;
        }

        foreach ($enabled as $gateway) {
            if (! ($gateway['available'] ?? false)) {
                return false;
            }
        }

        return true;
    }
}
