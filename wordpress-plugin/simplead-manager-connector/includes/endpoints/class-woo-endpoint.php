<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce health endpoint (Faza 5.2, conditional).
 *
 * Read-only. Detection is free: WooCommerce is a plugin, already surfaced in the
 * Manager's SitePlugin inventory (slug 'woocommerce', is_active) after a sync — so
 * this endpoint adds no detection column of its own. It is simply gated at runtime
 * with class_exists('WooCommerce'): on a site without WooCommerce it returns
 * 200 {applicable:false} and does nothing else.
 *
 * When WooCommerce is present it reports three cheap signals the Manager maps to a
 * traffic-light status:
 *   - pending_orders  : count of wc-pending orders older than a threshold (hours)
 *   - gateways        : the registered payment gateways with enabled/available
 *   - scheduled_sales_cron_present : whether 'woocommerce_scheduled_sales' is queued
 */
class SAM_Woo_Endpoint extends SAM_Endpoint_Base {

    /** Default age (hours) beyond which a still-pending order is "stuck". */
    private const DEFAULT_PENDING_HOURS = 24;

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/woo-health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'woo_health'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'pending_hours' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public function woo_health(WP_REST_Request $request): WP_REST_Response {
        // Runtime gate: no WooCommerce → nothing applies. Detection itself lives in
        // the Manager's plugin inventory, so we don't duplicate it here.
        if (!class_exists('WooCommerce')) {
            return $this->success(['applicable' => false]);
        }

        $pending_hours = (int) $request->get_param('pending_hours');
        if ($pending_hours <= 0) {
            $pending_hours = self::DEFAULT_PENDING_HOURS;
        }

        $pending_count = $this->count_stuck_pending_orders($pending_hours);

        return $this->success([
            'applicable'                   => true,
            'pending_hours'                => $pending_hours,
            'pending_orders_count'         => $pending_count,
            'pending_over_threshold'       => $pending_count > 0,
            'gateways'                     => $this->collect_gateways(),
            'scheduled_sales_cron_present' => $this->has_scheduled_sales_cron(),
        ]);
    }

    /**
     * Count orders still in 'wc-pending' older than $hours.
     *
     * Prefers wc_get_orders(), which transparently reads whichever storage the site
     * uses (HPOS wc_orders table or legacy shop_order posts). Falls back to a direct
     * $wpdb query if the CRUD helper is unavailable. shell_exec is disabled on WP
     * hosts and is never used.
     */
    private function count_stuck_pending_orders(int $hours): int {
        $cutoff = time() - ($hours * HOUR_IN_SECONDS);

        if (function_exists('wc_get_orders')) {
            $order_ids = wc_get_orders([
                'status'       => 'wc-pending',
                'date_created' => '<' . $cutoff,
                'limit'        => -1,
                'return'       => 'ids',
                'type'         => 'shop_order',
            ]);

            return is_array($order_ids) ? count($order_ids) : 0;
        }

        return $this->count_stuck_pending_orders_fallback($cutoff);
    }

    /**
     * Direct-SQL fallback covering both HPOS and legacy post storage.
     */
    private function count_stuck_pending_orders_fallback(int $cutoff): int {
        global $wpdb;

        $cutoff_gmt = gmdate('Y-m-d H:i:s', $cutoff);

        // HPOS: dedicated orders table.
        $hpos_table = $wpdb->prefix . 'wc_orders';
        $has_hpos = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $hpos_table)
        );

        if ($has_hpos === $hpos_table) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$hpos_table}
                 WHERE type = %s AND status = %s AND date_created_gmt < %s",
                'shop_order',
                'wc-pending',
                $cutoff_gmt
            ));

            return (int) $count;
        }

        // Legacy: shop_order posts in wp_posts.
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = %s AND post_date_gmt < %s",
            'shop_order',
            'wc-pending',
            $cutoff_gmt
        ));

        return (int) $count;
    }

    /**
     * The registered payment gateways with their enabled/available flags. A gateway
     * enabled but not available (misconfigured / failing) is what the Manager treats
     * as "gateway down".
     *
     * @return array<int, array{id: string, title: string, enabled: bool, available: bool}>
     */
    private function collect_gateways(): array {
        $out = [];

        if (!function_exists('WC') || !WC()->payment_gateways) {
            return $out;
        }

        $gateways = WC()->payment_gateways->payment_gateways();
        if (!is_array($gateways)) {
            return $out;
        }

        foreach ($gateways as $gateway) {
            $enabled = isset($gateway->enabled) && $gateway->enabled === 'yes';

            // is_available() can be pricey/throw in odd setups — never let one
            // gateway break the whole health report.
            try {
                $available = method_exists($gateway, 'is_available')
                    ? (bool) $gateway->is_available()
                    : $enabled;
            } catch (\Throwable $e) {
                $available = false;
            }

            $out[] = [
                'id'        => (string) ($gateway->id ?? ''),
                'title'     => method_exists($gateway, 'get_title')
                    ? (string) $gateway->get_title()
                    : (string) ($gateway->title ?? ''),
                'enabled'   => $enabled,
                'available' => $available,
            ];
        }

        return $out;
    }

    /**
     * Whether WooCommerce's daily scheduled-sales hook is queued. Reuses the cron
     * inspection pattern from SAM_Cron_Endpoint (_get_cron_array()). If the hook is
     * missing, timed sale price start/end dates silently stop applying.
     */
    private function has_scheduled_sales_cron(): bool {
        if (wp_next_scheduled('woocommerce_scheduled_sales') !== false) {
            return true;
        }

        // Defensive scan in case the schedule is present under an args hash that
        // wp_next_scheduled() with no args does not match.
        $crons = _get_cron_array();
        if (empty($crons)) {
            return false;
        }

        foreach ($crons as $hooks) {
            if (isset($hooks['woocommerce_scheduled_sales'])) {
                return true;
            }
        }

        return false;
    }
}
