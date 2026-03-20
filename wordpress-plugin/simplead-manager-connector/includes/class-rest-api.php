<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers all REST API routes.
 */
class SAM_REST_API {

    private array $endpoints = [];

    public function __construct() {
        $classes = [
            'SAM_Info_Endpoint',
            'SAM_Plugins_Endpoint',
            'SAM_Themes_Endpoint',
            'SAM_Users_Endpoint',
            'SAM_Core_Endpoint',
            'SAM_Health_Endpoint',
            'SAM_Security_Endpoint',
            'SAM_Security_Settings_Endpoint',
            'SAM_Backup_Endpoint',
            'SAM_Rollback_Endpoint',
            'SAM_Database_Endpoint',
            'SAM_Cron_Endpoint',
            'SAM_Monitoring_Endpoint',
            'SAM_Audit_Endpoint',
            'SAM_Login_Endpoint',
            'SAM_Self_Update_Endpoint',
            'SAM_Cache_Endpoint',
            'SAM_Diagnostic_Endpoint',
            'SAM_Site_Tweaks_Endpoint',
        ];

        foreach ($classes as $class) {
            try {
                if (class_exists($class)) {
                    $this->endpoints[] = new $class();
                }
            } catch (\Throwable $e) {
                // Skip broken endpoints so the rest (including self-update) keep working
            }
        }
    }

    public function register_routes(): void {
        foreach ($this->endpoints as $endpoint) {
            $endpoint->register_routes();
        }

        // Log all requests to the simplead/v1 namespace
        add_filter('rest_post_dispatch', [$this, 'log_request'], 10, 3);
    }

    /**
     * Log REST API requests targeting our namespace.
     */
    public function log_request(WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request): WP_REST_Response {
        $route = $request->get_route();

        // Only log requests to our namespace
        if (strpos($route, '/' . SAM_REST_NAMESPACE) !== 0) {
            return $response;
        }

        SAM_Request_Logger::log(
            SAM_Request_Logger::get_client_ip(),
            $route,
            $request->get_method(),
            $response->get_status(),
            $request->get_header('X-SAM-Key'),
            $request->get_header('User-Agent')
        );

        return $response;
    }
}

/**
 * Base class for all endpoint controllers.
 */
abstract class SAM_Endpoint_Base {

    abstract public function register_routes(): void;

    /**
     * Standard permission callback: IP Whitelist → Rate Limiter → HMAC Auth
     */
    public function check_permission(WP_REST_Request $request) {
        // IP whitelist check first (cheapest — array lookup)
        $ip_check = SAM_IP_Whitelist::check();
        if (is_wp_error($ip_check)) {
            return $ip_check;
        }

        // Rate limit check (transient lookup)
        $rate_check = SAM_Rate_Limiter::check($request);
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }

        // HMAC authentication (crypto — most expensive)
        return SAM_Authentication::validate($request);
    }

    /**
     * Return a success response.
     */
    protected function success(array $data = [], int $status = 200): WP_REST_Response {
        return new WP_REST_Response(
            array_merge(['success' => true], $data),
            $status
        );
    }

    /**
     * Return an error response.
     */
    protected function error(string $code, string $message, int $status = 400): WP_Error {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
