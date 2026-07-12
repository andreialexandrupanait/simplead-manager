<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Audit log retrieval endpoint.
 */
class SAM_Audit_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/audit-logs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_audit_logs'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function get_audit_logs(WP_REST_Request $request): WP_REST_Response {
        $since = $request->get_param('since');

        if ($since) {
            $since = sanitize_text_field($since);
        }

        // Page size (clamped) and order. 'asc' lets the manager paginate a burst
        // forward from its watermark instead of losing everything past the newest
        // page (P1-52). Default stays DESC/newest-first for backwards compatibility.
        $limit = (int) $request->get_param('limit');
        $limit = $limit > 0 ? min($limit, 1000) : 500;

        $order = strtolower((string) $request->get_param('order')) === 'asc' ? 'asc' : 'desc';

        $logs = SAM_Audit_Logger::get_logs($since, $limit, $order);

        return $this->success([
            'logs'  => $logs,
            'count' => count($logs),
            'since' => $since,
            'order' => $order,
            'limit' => $limit,
        ]);
    }
}
