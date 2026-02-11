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

        $logs = SAM_Audit_Logger::get_logs($since);

        return $this->success([
            'logs'  => $logs,
            'count' => count($logs),
            'since' => $since,
        ]);
    }
}
