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
        $this->endpoints = [
            new SAM_Info_Endpoint(),
            new SAM_Plugins_Endpoint(),
            new SAM_Themes_Endpoint(),
            new SAM_Users_Endpoint(),
            new SAM_Core_Endpoint(),
            new SAM_Health_Endpoint(),
            new SAM_Security_Endpoint(),
            new SAM_Backup_Endpoint(),
            new SAM_Rollback_Endpoint(),
            new SAM_Database_Endpoint(),
            new SAM_Cron_Endpoint(),
            new SAM_Monitoring_Endpoint(),
            new SAM_Audit_Endpoint(),
            new SAM_Login_Endpoint(),
            new SAM_Self_Update_Endpoint(),
            new SAM_Cache_Endpoint(),
        ];
    }

    public function register_routes(): void {
        foreach ($this->endpoints as $endpoint) {
            $endpoint->register_routes();
        }
    }
}

/**
 * Base class for all endpoint controllers.
 */
abstract class SAM_Endpoint_Base {

    abstract public function register_routes(): void;

    /**
     * Standard permission callback using HMAC authentication + rate limiting.
     */
    public function check_permission(WP_REST_Request $request) {
        // Rate limit check first (cheap, avoids HMAC computation on abuse)
        $rate_check = SAM_Rate_Limiter::check($request);
        if (is_wp_error($rate_check)) {
            return $rate_check;
        }

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
