<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PHP errors endpoint (SPEC §5.6).
 *
 * Serves the DEDUPLICATED aggregate that {@see SAM_Error_Collector} builds from
 * its own set_error_handler()/register_shutdown_function() pair — not a tail of
 * debug.log, which the spec rules out.
 *
 * Three routes, matching the three things Manager needs:
 *   GET  /php-errors            — drain-friendly read of the aggregate
 *   POST /php-errors/ack        — drop the signatures Manager has stored
 *   POST /php-errors/toggle     — the remote kill switch (spec point 4)
 *
 * Acknowledgement is a separate call on purpose: if the transfer fails, nothing
 * is lost, because the site only forgets what Manager confirms it has.
 */
class SAM_Php_Errors_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/php-errors', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_errors'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/php-errors/ack', [
            'methods'             => 'POST',
            'callback'            => [$this, 'acknowledge'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'signatures' => ['type' => 'array', 'required' => false],
            ],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/php-errors/toggle', [
            'methods'             => 'POST',
            'callback'            => [$this, 'toggle'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'enabled' => ['type' => 'boolean', 'required' => true],
            ],
        ]);
    }

    public function get_errors(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('SAM_Error_Collector')) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'collector_unavailable',
            ], 500);
        }

        return new WP_REST_Response([
            'success'   => true,
            'enabled'   => SAM_Error_Collector::is_enabled(),
            'errors'    => SAM_Error_Collector::get_aggregate(),
            'collected_at' => gmdate('c'),
        ], 200);
    }

    public function acknowledge(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('SAM_Error_Collector')) {
            return new WP_REST_Response(['success' => false, 'error' => 'collector_unavailable'], 500);
        }

        $signatures = $request->get_param('signatures');
        $signatures = is_array($signatures) ? array_map('strval', $signatures) : [];

        return new WP_REST_Response([
            'success' => true,
            'removed' => SAM_Error_Collector::acknowledge($signatures),
        ], 200);
    }

    /**
     * SPEC §5.6 point 4 — "Buton de oprire de la distanță, din Manager."
     */
    public function toggle(WP_REST_Request $request): WP_REST_Response {
        if (!class_exists('SAM_Error_Collector')) {
            return new WP_REST_Response(['success' => false, 'error' => 'collector_unavailable'], 500);
        }

        $enabled = (bool) $request->get_param('enabled');
        SAM_Error_Collector::set_enabled($enabled);

        return new WP_REST_Response([
            'success' => true,
            'enabled' => SAM_Error_Collector::is_enabled(),
        ], 200);
    }
}
