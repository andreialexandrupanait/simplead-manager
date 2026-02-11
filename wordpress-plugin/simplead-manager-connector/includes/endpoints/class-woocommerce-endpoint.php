<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce integration endpoints.
 * Only functional when WooCommerce is active.
 */
class SAM_WooCommerce_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/woo/stats', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/woo/low-stock', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_low_stock'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/woo/out-of-stock', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_out_of_stock'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    private function woo_active(): bool {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active('woocommerce/woocommerce.php');
    }

    public function get_stats(WP_REST_Request $request): WP_REST_Response {
        if (!$this->woo_active()) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'WOO_NOT_ACTIVE', 'message' => 'WooCommerce is not active.'],
            ], 400);
        }

        global $wpdb;

        $period = sanitize_text_field($request->get_param('period') ?: 'today');

        $date_filter = $this->get_date_filter($period);

        // Revenue and order count
        $order_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT p.ID) as order_count,
                COALESCE(SUM(pm_total.meta_value), 0) as revenue
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
            AND p.post_date >= %s",
            $date_filter
        ));

        // Average order value
        $avg_order = $order_stats && $order_stats->order_count > 0
            ? round($order_stats->revenue / $order_stats->order_count, 2)
            : 0;

        // Total products
        $total_products = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
        );

        // Total customers (users with customer role or with orders)
        $total_customers = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.meta_value) FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.post_type = 'shop_order' AND pm.meta_key = '_customer_user' AND pm.meta_value > 0"
        );

        // Refund count for period
        $refund_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'shop_order_refund' AND post_date >= %s",
            $date_filter
        ));

        return $this->success([
            'period'          => $period,
            'revenue'         => round((float) ($order_stats->revenue ?? 0), 2),
            'order_count'     => (int) ($order_stats->order_count ?? 0),
            'avg_order_value' => $avg_order,
            'total_products'  => $total_products,
            'total_customers' => $total_customers,
            'refund_count'    => $refund_count,
            'currency'        => get_woocommerce_currency(),
        ]);
    }

    public function get_low_stock(WP_REST_Request $request): WP_REST_Response {
        if (!$this->woo_active()) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'WOO_NOT_ACTIVE', 'message' => 'WooCommerce is not active.'],
            ], 400);
        }

        $low_stock_threshold = (int) get_option('woocommerce_notify_low_stock_amount', 2);

        $products = wc_get_products([
            'status'       => 'publish',
            'stock_status' => 'instock',
            'manage_stock' => true,
            'limit'        => 50,
            'orderby'      => 'meta_value_num',
            'meta_key'     => '_stock',
            'order'        => 'ASC',
        ]);

        $low_stock = [];
        foreach ($products as $product) {
            $stock = $product->get_stock_quantity();
            if ($stock !== null && $stock <= $low_stock_threshold && $stock > 0) {
                $low_stock[] = [
                    'id'        => $product->get_id(),
                    'name'      => $product->get_name(),
                    'sku'       => $product->get_sku(),
                    'stock'     => $stock,
                    'threshold' => $low_stock_threshold,
                    'price'     => $product->get_price(),
                    'permalink' => $product->get_permalink(),
                    'edit_url'  => get_edit_post_link($product->get_id(), 'raw'),
                ];
            }
        }

        return $this->success([
            'products'  => $low_stock,
            'count'     => count($low_stock),
            'threshold' => $low_stock_threshold,
        ]);
    }

    public function get_out_of_stock(WP_REST_Request $request): WP_REST_Response {
        if (!$this->woo_active()) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'WOO_NOT_ACTIVE', 'message' => 'WooCommerce is not active.'],
            ], 400);
        }

        $products = wc_get_products([
            'status'       => 'publish',
            'stock_status' => 'outofstock',
            'limit'        => 50,
            'orderby'      => 'title',
            'order'        => 'ASC',
        ]);

        $out_of_stock = [];
        foreach ($products as $product) {
            $out_of_stock[] = [
                'id'        => $product->get_id(),
                'name'      => $product->get_name(),
                'sku'       => $product->get_sku(),
                'stock'     => $product->get_stock_quantity(),
                'price'     => $product->get_price(),
                'permalink' => $product->get_permalink(),
                'edit_url'  => get_edit_post_link($product->get_id(), 'raw'),
            ];
        }

        return $this->success([
            'products' => $out_of_stock,
            'count'    => count($out_of_stock),
        ]);
    }

    private function get_date_filter(string $period): string {
        return match ($period) {
            'today'      => date('Y-m-d 00:00:00'),
            'yesterday'  => date('Y-m-d 00:00:00', strtotime('-1 day')),
            'week'       => date('Y-m-d 00:00:00', strtotime('-7 days')),
            'month'      => date('Y-m-01 00:00:00'),
            'last_month' => date('Y-m-01 00:00:00', strtotime('first day of last month')),
            'year'       => date('Y-01-01 00:00:00'),
            default      => date('Y-m-d 00:00:00'),
        };
    }
}
