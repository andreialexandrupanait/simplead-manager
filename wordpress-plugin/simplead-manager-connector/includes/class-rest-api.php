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
            'SAM_SEO_Endpoint',
            'SAM_Redirects_Endpoint',
            'SAM_Posts_Endpoint',
            'SAM_Error_Logs_Endpoint',
            'SAM_Key_Rotation_Endpoint',
            'SAM_Content_Urls_Endpoint',
            'SAM_Woo_Endpoint',
            'SAM_Form_Test_Endpoint',
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
     * Standard permission callback: Rate Limiter → HMAC Auth → whitelist record.
     *
     * ORDER IS SECURITY-CRITICAL AND CHANGED IN 2.19.0.
     *
     * Up to 2.18.0 the IP whitelist was evaluated FIRST, before authentication. Because
     * /info auto-adds the calling IP, every synced site ended up with a whitelist holding
     * exactly one address — the manager's. Moving the manager to a new address therefore
     * produced 403 IP_NOT_WHITELISTED on every route including /info, so the
     * self-bootstrapping auto-whitelist could never run from the new address. The IP
     * change was unrecoverable without editing each site by hand.
     *
     * The whitelist is not removed and authentication is not weakened. What changed is
     * that the list is now WRITTEN only after a request has proven it holds this site's
     * API key and secret: rate limit → full HMAC validation (key, timestamp, nonce,
     * signature) → record the authenticated IP. A request with a missing, expired,
     * replayed or forged signature is rejected and cannot touch the list.
     */
    public function check_permission(WP_REST_Request $request) {
        // 1. Rate limit (transient lookup, keyed on the presented API key).
        $rate_check = SAM_Rate_Limiter::check($request);
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }

        // 2. HMAC authentication (crypto). Nothing below runs unless this passes.
        $auth_check = SAM_Authentication::validate($request);
        if (is_wp_error($auth_check)) {
            return $auth_check;
        }

        // 3. Authenticated — record this IP so the whitelist follows a legitimate
        //    manager IP change. Additive: existing entries are preserved.
        SAM_IP_Whitelist::allow_authenticated_ip(SAM_Request_Logger::get_client_ip());

        return true;
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
